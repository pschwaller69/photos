#!/usr/bin/env python3
"""
Indexeur de photos : parcourt le NAS, lit les métadonnées (EXIF, IPTC, XMP, catalogue Lightroom),
fait décrire chaque photo par l'IA locale (Ollama) et envoie miniature + infos au site o2switch.

Usage (dans une invite de commandes, depuis ce dossier) :
    python photoindex.py --test          vérifie la configuration
    python photoindex.py                 indexe les nouvelles photos et les photos modifiées
    python photoindex.py --limite 20     n'en traite que 20 (pour essayer)
    python photoindex.py --nettoyer      retire du site les photos supprimées du NAS
    python photoindex.py --veille        rédige les textes de publication demandés depuis le téléphone
    python photoindex.py --aide          toutes les options
"""
from __future__ import annotations

import argparse
import base64
import configparser
import hashlib
import io
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path

try:
    import requests
    from PIL import Image, ImageOps
except ImportError:
    print("Il manque des modules Python. Lancez :  pip install -r requirements.txt")
    sys.exit(1)

try:  # facultatif : photos HEIC d'iPhone
    from pillow_heif import register_heif_opener
    register_heif_opener()
    HEIF_OK = True
except Exception:
    HEIF_OK = False

Image.MAX_IMAGE_PIXELS = 400_000_000  # panoramas

HERE = Path(__file__).resolve().parent
STATE_DB = HERE / "etat.sqlite"
LOG_FILE = HERE / "journal.log"
RAW_EXT = {".dng", ".cr2", ".cr3", ".nef", ".arw", ".raf", ".orf", ".rw2", ".pef", ".srw", ".nrw"}
PIL_EXT = {".jpg", ".jpeg", ".png", ".tif", ".tiff", ".webp", ".bmp", ".psd"}
EXIF_TAGS = [
    "DateTimeOriginal", "CreateDate", "DateCreated", "Make", "Model", "LensModel", "Lens", "LensID",
    "FocalLength", "FNumber", "ExposureTime", "ISO", "ImageWidth", "ImageHeight", "ExifImageWidth",
    "ExifImageHeight", "Orientation", "Rating", "Label", "Subject", "HierarchicalSubject", "Keywords",
    "Title", "ObjectName", "Description", "ImageDescription", "Caption-Abstract", "City", "State",
    "Province-State", "Country", "Country-PrimaryLocationName", "Location", "Sub-location",
    "GPSLatitude", "GPSLongitude", "GPSLatitudeRef", "GPSLongitudeRef",
]

# --------------------------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------------------------


@dataclass
class Config:
    roots: list[str]
    ignore: list[str]
    extensions: set[str]
    site_url: str
    api_key: str
    ai_on: bool
    ai_engine: str
    ai_url: str
    ai_model: str
    ai_size: int
    ai_timeout: int
    thumb_h: int
    medium: int
    quality: int
    lr_catalog: str
    lr_map: list[tuple[str, str]]
    exiftool: str

    @property
    def preview_sig(self) -> str:
        return f"{self.thumb_h}/{self.medium}/{self.quality}"


def lines(v: str) -> list[str]:
    return [x.strip() for x in (v or "").splitlines() if x.strip()]


def load_config(path: Path) -> Config:
    if not path.exists():
        sys.exit(f"Fichier {path.name} introuvable : copiez config.example.ini en config.ini et remplissez-le.")
    cp = configparser.ConfigParser(comment_prefixes=(";",), inline_comment_prefixes=None, interpolation=None)
    cp.read(path, encoding="utf-8-sig")
    g = lambda s, k, d="": cp.get(s, k, fallback=d).strip()  # noqa: E731
    lr_map = []
    for ln in lines(cp.get("lightroom", "correspondances", fallback="")):
        if "=>" in ln:
            a, b = ln.split("=>", 1)
            lr_map.append((a.strip(), b.strip()))
    exiftool = g("outils", "exiftool")
    if not exiftool:
        for cand in (HERE / "exiftool.exe", HERE / "exiftool(-k).exe", HERE / "exiftool"):
            if cand.exists():
                exiftool = str(cand)
                break
        else:
            exiftool = shutil.which("exiftool") or "exiftool"
    return Config(
        roots=lines(cp.get("photos", "dossiers", fallback="")),
        ignore=[x.lower() for x in lines(cp.get("photos", "ignorer", fallback=""))],
        extensions={e.lower() if e.startswith(".") else "." + e.lower()
                    for e in g("photos", "extensions", ".jpg .jpeg").split()},
        site_url=g("site", "url").rstrip("/"),
        api_key=g("site", "cle_api"),
        ai_on=g("ia", "active", "oui").lower() in ("oui", "yes", "1", "true", "vrai"),
        ai_engine=g("ia", "moteur", "ollama").lower(),
        ai_url=g("ia", "url", "http://localhost:11434").rstrip("/"),
        ai_model=g("ia", "modele", "gemma3:12b"),
        ai_size=int(g("ia", "taille_image", "1024") or 1024),
        ai_timeout=int(g("ia", "delai", "180") or 180),
        thumb_h=int(g("apercus", "miniature_hauteur", "440") or 440),
        medium=int(g("apercus", "apercu_taille", "1600") or 0),
        quality=int(g("apercus", "qualite", "80") or 80),
        lr_catalog=g("lightroom", "catalogue"),
        lr_map=lr_map,
        exiftool=exiftool,
    )


# --------------------------------------------------------------------------------------------
# Journal
# --------------------------------------------------------------------------------------------

def log(msg: str, also_print: bool = True) -> None:
    line = f"{datetime.now():%Y-%m-%d %H:%M:%S}  {msg}"
    if also_print:
        print(msg, flush=True)
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except OSError:
        pass


# --------------------------------------------------------------------------------------------
# État local (ce qui a déjà été envoyé)
# --------------------------------------------------------------------------------------------

class State:
    def __init__(self, path: Path):
        self.db = sqlite3.connect(path)
        self.db.executescript("""
            CREATE TABLE IF NOT EXISTS files (
                path TEXT PRIMARY KEY, size INTEGER, mtime REAL, uid TEXT, sig TEXT, seen INTEGER);
            CREATE TABLE IF NOT EXISTS ai (
                uid TEXT PRIMARY KEY, model TEXT, result TEXT, created INTEGER);
            CREATE TABLE IF NOT EXISTS sent (
                uid TEXT PRIMARY KEY, previews TEXT);
        """)

    def file(self, path: str):
        return self.db.execute("SELECT size, mtime, uid, sig FROM files WHERE path = ?", (path,)).fetchone()

    def save_file(self, path, size, mtime, uid, sig, seen):
        self.db.execute("INSERT OR REPLACE INTO files VALUES (?,?,?,?,?,?)", (path, size, mtime, uid, sig, seen))
        self.db.commit()

    def touch(self, paths: list[str], seen: int):
        self.db.executemany("UPDATE files SET seen = ? WHERE path = ?", [(seen, p) for p in paths])
        self.db.commit()

    def ai_get(self, uid: str):
        r = self.db.execute("SELECT model, result FROM ai WHERE uid = ?", (uid,)).fetchone()
        return (r[0], json.loads(r[1])) if r else None

    def ai_put(self, uid: str, model: str, result: dict):
        self.db.execute("INSERT OR REPLACE INTO ai VALUES (?,?,?,?)",
                        (uid, model, json.dumps(result, ensure_ascii=False), int(time.time())))
        self.db.commit()

    def previews_sent(self, uid: str) -> str | None:
        r = self.db.execute("SELECT previews FROM sent WHERE uid = ?", (uid,)).fetchone()
        return r[0] if r else None

    def mark_sent(self, uid: str, previews: str):
        self.db.execute("INSERT OR REPLACE INTO sent VALUES (?, ?)", (uid, previews))
        self.db.commit()

    def forget_sent(self, uids: list[str]):
        self.db.executemany("DELETE FROM sent WHERE uid = ?", [(u,) for u in uids])
        self.db.commit()

    def uids_seen(self, seen: int) -> set[str]:
        return {r[0] for r in self.db.execute("SELECT uid FROM files WHERE seen = ? AND uid IS NOT NULL", (seen,))}

    def drop_unseen(self, seen: int) -> int:
        n = self.db.execute("DELETE FROM files WHERE seen <> ?", (seen,)).rowcount
        self.db.commit()
        return n


# --------------------------------------------------------------------------------------------
# Parcours du NAS
# --------------------------------------------------------------------------------------------

@dataclass
class FileInfo:
    path: str
    size: int
    mtime: float
    sidecar: str | None = None
    sidecar_mtime: float = 0.0


def scan(cfg: Config, only: str | None = None) -> tuple[list[FileInfo], list[str]]:
    files: list[FileInfo] = []
    missing: list[str] = []
    roots = [only] if only else cfg.roots
    for root in roots:
        if not os.path.isdir(root):
            missing.append(root)
            continue
        for dirpath, dirnames, filenames in os.walk(root):
            low = dirpath.lower()
            dirnames[:] = [d for d in dirnames
                           if not d.startswith(".") and not any(ig in (low + os.sep + d.lower()) for ig in cfg.ignore)]
            lower_names = {f.lower(): f for f in filenames}
            for name in filenames:
                ext = os.path.splitext(name)[1].lower()
                if ext not in cfg.extensions or name.startswith("._"):
                    continue
                full = os.path.join(dirpath, name)
                try:
                    st = os.stat(full)
                except OSError:
                    continue
                fi = FileInfo(full, st.st_size, round(st.st_mtime, 3))
                # fichier annexe .xmp de Lightroom (surtout pour les RAW)
                base = os.path.splitext(name)[0].lower()
                for cand in (base + ".xmp", name.lower() + ".xmp"):
                    if cand in lower_names:
                        side = os.path.join(dirpath, lower_names[cand])
                        try:
                            fi.sidecar, fi.sidecar_mtime = side, round(os.stat(side).st_mtime, 3)
                        except OSError:
                            pass
                        break
                files.append(fi)
    files.sort(key=lambda f: f.path.lower())
    return files, missing


def fingerprint(path: str, size: int) -> str:
    """Identifiant stable de la photo : empreinte de la fin du fichier (les données d'image).
    Ne change pas quand Lightroom réécrit les métadonnées en tête de fichier, ni quand on déplace la photo."""
    n = 262_144
    with open(path, "rb") as f:
        if size > n:
            f.seek(size - n)
        data = f.read(n)
    return hashlib.sha1(b"photoindex-v1" + data).hexdigest()


# --------------------------------------------------------------------------------------------
# exiftool
# --------------------------------------------------------------------------------------------

def run_exiftool(cfg: Config, paths: list[str]) -> dict[str, dict]:
    """Lit les métadonnées d'un lot de fichiers. Renvoie {chemin_normalisé: dict}."""
    if not paths:
        return {}
    with tempfile.NamedTemporaryFile("w", suffix=".args", delete=False, encoding="utf-8") as af:
        af.write("\n".join(["-j", "-n", "-fast", "-charset", "filename=utf8", "-api", "largefilesupport=1"]
                           + [f"-{t}" for t in EXIF_TAGS] + paths) + "\n")
        argfile = af.name
    try:
        p = subprocess.run([cfg.exiftool, "-@", argfile], capture_output=True, timeout=600,
                           creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        out = p.stdout.decode("utf-8", "replace").strip()
        data = json.loads(out) if out else []
    except FileNotFoundError:
        sys.exit("exiftool introuvable. Téléchargez-le sur https://exiftool.org et placez exiftool.exe à côté de ce script.")
    except (json.JSONDecodeError, subprocess.TimeoutExpired) as e:
        log(f"  ! exiftool : {e}")
        data = []
    finally:
        os.unlink(argfile)
    return {os.path.normcase(os.path.normpath(d.get("SourceFile", ""))): d for d in data}


def exif_binary(cfg: Config, path: str) -> bytes | None:
    """Extrait l'aperçu JPEG intégré à un RAW."""
    for tag in ("-JpgFromRaw", "-PreviewImage", "-ThumbnailImage"):
        try:
            p = subprocess.run([cfg.exiftool, "-b", tag, path], capture_output=True, timeout=120,
                               creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            if len(p.stdout) > 10_000:
                return p.stdout
        except Exception:
            pass
    return None


# --------------------------------------------------------------------------------------------
# Catalogue Lightroom (facultatif)
# --------------------------------------------------------------------------------------------

def norm_path(p: str) -> str:
    return p.replace("\\", "/").rstrip("/").lower()


def tail_key(p: str, n: int = 3) -> str:
    return "/".join(norm_path(p).split("/")[-n:])


class LightroomCatalog:
    def __init__(self, cfg: Config):
        self.by_path: dict[str, dict] = {}
        self.by_tail: dict[str, dict | None] = {}
        self.ok = False
        if not cfg.lr_catalog:
            return
        src = Path(cfg.lr_catalog)
        if not src.exists():
            log(f"! Catalogue Lightroom introuvable : {src}")
            return
        tmp = Path(tempfile.gettempdir()) / "photoindex-catalogue.lrcat"
        try:
            shutil.copy2(src, tmp)
            self._load(tmp, cfg)
            self.ok = True
            log(f"Catalogue Lightroom lu : {len(self.by_path)} photos.")
        except Exception as e:  # le schéma Lightroom peut varier selon les versions
            log(f"! Lecture du catalogue Lightroom impossible ({e}). On continue avec les métadonnées des fichiers.")
        finally:
            try:
                tmp.unlink()
            except OSError:
                pass

    @staticmethod
    def _cols(db, table: str) -> set[str]:
        try:
            return {r[1] for r in db.execute(f"PRAGMA table_info({table})")}
        except sqlite3.Error:
            return set()

    def _load(self, path: Path, cfg: Config) -> None:
        db = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
        rows = db.execute("""
            SELECT i.id_local, rf.absolutePath, fo.pathFromRoot, fi.baseName, fi.extension, i.rating, i.colorLabels
            FROM Adobe_images i
            JOIN AgLibraryFile fi ON fi.id_local = i.rootFile
            JOIN AgLibraryFolder fo ON fo.id_local = fi.folder
            JOIN AgLibraryRootFolder rf ON rf.id_local = fo.rootFolder
            WHERE i.masterImage IS NULL
        """).fetchall()
        info: dict[int, dict] = {}
        for img_id, root, sub, base, ext, rating, label in rows:
            full = f"{root or ''}{sub or ''}{base}.{ext}"
            for a, b in cfg.lr_map:
                if norm_path(full).startswith(norm_path(a)):
                    full = b.rstrip("\\/") + "/" + full[len(a.rstrip("\\/")) + 1:]
                    break
            d = {"rating": int(rating) if rating is not None else None, "label": label or None, "keywords": []}
            info[img_id] = d
            self.by_path[norm_path(full)] = d
            tk = tail_key(full)
            self.by_tail[tk] = None if tk in self.by_tail else d  # None = ambigu

        # mots-clés (avec leurs parents)
        kw = {r[0]: (r[1], r[2]) for r in db.execute("SELECT id_local, name, parent FROM AgLibraryKeyword")}

        def chain(k):
            out, seen = [], set()
            while k in kw and k not in seen:
                seen.add(k)
                name, parent = kw[k]
                if name:
                    out.append(name)
                k = parent
            return out
        for img, tag in db.execute("SELECT image, tag FROM AgLibraryKeywordImage"):
            if img in info:
                for n in chain(tag):
                    if n not in info[img]["keywords"]:
                        info[img]["keywords"].append(n)

        # légende / titre
        cols = self._cols(db, "AgLibraryIPTC")
        wanted = [c for c in ("caption", "title") if c in cols]
        if wanted and "image" in cols:
            for r in db.execute(f"SELECT image, {', '.join(wanted)} FROM AgLibraryIPTC"):
                if r[0] in info:
                    for c, v in zip(wanted, r[1:]):
                        if v:
                            info[r[0]][c] = v

        # lieu (ville, région, pays, lieu)
        cols = self._cols(db, "AgHarvestedIptcMetadata")
        refs = {"city": ("cityRef", "AgInternedIptcCity"), "region": ("stateRef", "AgInternedIptcState"),
                "country": ("countryRef", "AgInternedIptcCountry"), "location": ("locationRef", "AgInternedIptcLocation")}
        for key, (col, table) in refs.items():
            if col in cols and self._cols(db, table):
                q = f"SELECT h.image, t.value FROM AgHarvestedIptcMetadata h JOIN {table} t ON t.id_local = h.{col}"
                for img, v in db.execute(q):
                    if img in info and v:
                        info[img][key] = v

        # GPS
        cols = self._cols(db, "AgHarvestedExifMetadata")
        if {"gpsLatitude", "gpsLongitude", "image"} <= cols:
            for img, la, lo in db.execute(
                    "SELECT image, gpsLatitude, gpsLongitude FROM AgHarvestedExifMetadata WHERE gpsLatitude IS NOT NULL"):
                if img in info:
                    info[img]["lat"], info[img]["lon"] = la, lo
        db.close()

    def get(self, path: str) -> dict | None:
        if not self.ok:
            return None
        return self.by_path.get(norm_path(path)) or self.by_tail.get(tail_key(path))


# --------------------------------------------------------------------------------------------
# Métadonnées
# --------------------------------------------------------------------------------------------

def first(d: dict, *keys):
    for k in keys:
        v = d.get(k)
        if v not in (None, "", [], "-"):
            return v
    return None


def as_list(v) -> list[str]:
    if v is None:
        return []
    if isinstance(v, list):
        return [str(x).strip() for x in v if str(x).strip()]
    return [x.strip() for x in str(v).split(",") if x.strip()]


def parse_date(v) -> str | None:
    if not v:
        return None
    m = re.match(r"(\d{4})[:\-](\d{2})[:\-](\d{2})[ T]?(\d{2})?:?(\d{2})?:?(\d{2})?", str(v))
    if not m or m.group(1) == "0000":
        return None
    y, mo, d, hh, mi, ss = m.groups()
    return f"{y}-{mo}-{d} {hh or '00'}:{mi or '00'}:{ss or '00'}"


def fmt_exposure(v) -> str | None:
    try:
        x = float(v)
    except (TypeError, ValueError):
        return None
    if x <= 0:
        return None
    if x >= 0.5:
        return f"{x:g}"
    return f"1/{round(1 / x)}"


def build_meta(fi: FileInfo, ex: dict, side: dict, lr: dict | None, root_of: str) -> dict:
    # Le .xmp annexe (Lightroom) a priorité sur le fichier pour les champs qu'il contient
    d = dict(ex)
    d.update({k: v for k, v in side.items() if v not in (None, "", [])})

    keywords: list[str] = []
    for k in as_list(d.get("Subject")) + as_list(d.get("Keywords")):
        keywords.append(k)
    for h in as_list(d.get("HierarchicalSubject")):
        keywords.extend(p.strip() for p in h.split("|") if p.strip())

    make = (first(d, "Make") or "").strip()
    model = (first(d, "Model") or "").strip()
    camera = model if (not make or model.lower().startswith(make.split()[0].lower())) else f"{make} {model}"
    if make.lower().startswith("nikon corporation"):
        camera = model if model.lower().startswith("nikon") else f"Nikon {model}"

    lat, lon = first(d, "GPSLatitude"), first(d, "GPSLongitude")
    try:
        lat = float(lat) if lat is not None else None
        lon = float(lon) if lon is not None else None
        if lat is not None and str(d.get("GPSLatitudeRef", "")).upper().startswith("S") and lat > 0:
            lat = -lat
        if lon is not None and str(d.get("GPSLongitudeRef", "")).upper().startswith("W") and lon > 0:
            lon = -lon
        if lat == 0 and lon == 0:
            lat = lon = None
    except (TypeError, ValueError):
        lat = lon = None

    w = first(d, "ExifImageWidth", "ImageWidth")
    h = first(d, "ExifImageHeight", "ImageHeight")
    if str(d.get("Orientation")) in ("5", "6", "7", "8") and w and h:
        w, h = h, w

    lens = first(d, "LensModel", "Lens", "LensID")
    meta = {
        "path": fi.path,
        "filename": os.path.basename(fi.path),
        "folder": os.path.relpath(os.path.dirname(fi.path), root_of) if root_of else os.path.dirname(fi.path),
        "taken_at": parse_date(first(d, "DateTimeOriginal", "CreateDate", "DateCreated")),
        "camera": camera or None,
        "lens": str(lens) if lens else None,
        "focal": first(d, "FocalLength"),
        "aperture": first(d, "FNumber"),
        "exposure": fmt_exposure(first(d, "ExposureTime")),
        "iso": first(d, "ISO"),
        "width": w, "height": h,
        "rating": first(d, "Rating"),
        "label": first(d, "Label"),
        "keywords": keywords,
        "title": first(d, "Title", "ObjectName"),
        "caption": first(d, "Description", "Caption-Abstract", "ImageDescription"),
        "city": first(d, "City"),
        "region": first(d, "State", "Province-State"),
        "country": first(d, "Country", "Country-PrimaryLocationName"),
        "location": first(d, "Location", "Sub-location"),
        "lat": lat, "lon": lon,
        "file_size": fi.size,
    }
    if meta["folder"] == ".":
        meta["folder"] = ""
    if isinstance(meta["caption"], str) and meta["caption"].strip().lower() in ("", "olympus digital camera", "default"):
        meta["caption"] = None
    if lr:  # le catalogue Lightroom est la référence quand il est fourni
        for k in ("rating", "label", "caption", "title", "city", "region", "country", "location", "lat", "lon"):
            if lr.get(k) not in (None, ""):
                meta[k] = lr[k]
        meta["keywords"] = list(dict.fromkeys(meta["keywords"] + lr.get("keywords", [])))
    # dédoublonne les mots-clés sans tenir compte de la casse
    seen, kws = set(), []
    for k in meta["keywords"]:
        if k.lower() not in seen:
            seen.add(k.lower())
            kws.append(k)
    meta["keywords"] = kws
    for k in ("title", "caption", "city", "region", "country", "location", "label"):
        if meta[k] is not None:
            meta[k] = str(meta[k]).strip() or None
    return meta


# --------------------------------------------------------------------------------------------
# Images
# --------------------------------------------------------------------------------------------

ORIENT_OPS = {2: [Image.Transpose.FLIP_LEFT_RIGHT], 3: [Image.Transpose.ROTATE_180],
              4: [Image.Transpose.FLIP_TOP_BOTTOM], 5: [Image.Transpose.TRANSPOSE],
              6: [Image.Transpose.ROTATE_270], 7: [Image.Transpose.TRANSVERSE], 8: [Image.Transpose.ROTATE_90]}


def load_image(cfg: Config, fi: FileInfo, exif: dict) -> Image.Image:
    ext = os.path.splitext(fi.path)[1].lower()
    if ext in PIL_EXT or (ext in (".heic", ".heif") and HEIF_OK):
        im = Image.open(fi.path)
        im.draft("RGB", (2400, 2400))  # décodage JPEG accéléré (réduction à la volée)
        im = ImageOps.exif_transpose(im)
    else:
        data = exif_binary(cfg, fi.path)
        if not data:
            raise ValueError("aucun aperçu exploitable dans ce fichier")
        im = Image.open(io.BytesIO(data))
        if im.getexif().get(274) in (None, 1):
            try:
                o = int(exif.get("Orientation") or 1)
            except (TypeError, ValueError):
                o = 1
            for op in ORIENT_OPS.get(o, []):
                im = im.transpose(op)
        else:
            im = ImageOps.exif_transpose(im)
    if im.mode in ("I;16", "I;16B", "I;16L", "I"):
        im = im.point(lambda v: v * (1 / 256)).convert("L")
    if im.mode in ("RGBA", "LA", "P"):
        im = im.convert("RGBA")
        bg = Image.new("RGB", im.size, (255, 255, 255))
        bg.paste(im, mask=im.split()[-1])
        im = bg
    return im.convert("RGB")


def to_jpeg(im: Image.Image, quality: int) -> bytes:
    buf = io.BytesIO()
    im.save(buf, "JPEG", quality=quality, optimize=True, progressive=True)
    return buf.getvalue()


def resized(im: Image.Image, max_w: int, max_h: int) -> Image.Image:
    r = min(max_w / im.width, max_h / im.height, 1.0)
    if r >= 1.0:
        return im.copy()
    return im.resize((max(1, round(im.width * r)), max(1, round(im.height * r))), Image.Resampling.LANCZOS)


# --------------------------------------------------------------------------------------------
# IA locale
# --------------------------------------------------------------------------------------------

AI_SCHEMA = {
    "type": "object",
    "properties": {
        "description": {"type": "string"},
        "tags": {"type": "array", "items": {"type": "string"}},
        "texte": {"type": "string"},
    },
    "required": ["description", "tags", "texte"],
}

AI_PROMPT = """Tu aides à archiver une photothèque personnelle pour pouvoir retrouver les photos par une recherche.
Regarde attentivement la photo et réponds UNIQUEMENT avec un objet JSON, en français :
{
  "description": "2 à 4 phrases factuelles : le sujet principal, ce qui se passe, le lieu ou le type d'endroit, les personnes (nombre, âge approximatif, activité, sans les nommer), les animaux, les objets notables, la saison, la météo, le moment de la journée, l'ambiance",
  "tags": ["15 à 30 mots-clés courts, en minuscules, au singulier : sujets, objets, animaux (avec l'espèce ou la race si évident), type de lieu (plage, montagne, ville, forêt, intérieur…), activité ou événement (mariage, anniversaire, randonnée…), saison, météo, moment (coucher de soleil, nuit…), couleurs dominantes, type de photo (portrait, paysage, macro, selfie, groupe…)"],
  "texte": "texte lisible dans l'image (panneau, enseigne, maillot…), sinon chaîne vide"
}
N'invente rien que tu ne vois pas."""


def ai_hints(meta: dict) -> str:
    h = []
    if meta.get("folder"):
        h.append(f"dossier : « {meta['folder']} »")
    if meta.get("taken_at"):
        h.append(f"date : {meta['taken_at'][:10]}")
    place = ", ".join(x for x in (meta.get("location"), meta.get("city"), meta.get("country")) if x)
    if place:
        h.append(f"lieu : {place}")
    if meta.get("keywords"):
        h.append("mots-clés existants : " + ", ".join(meta["keywords"][:20]))
    if not h:
        return ""
    return "\n\nIndices sur la photo (à utiliser seulement s'ils sont cohérents avec l'image) : " + " ; ".join(h)


def parse_ai_json(text: str) -> dict:
    text = text.strip()
    text = re.sub(r"^```(?:json)?|```$", "", text, flags=re.M).strip()
    a, b = text.find("{"), text.rfind("}")
    if a < 0 or b < 0:
        raise ValueError(f"réponse IA non JSON : {text[:120]}")
    d = json.loads(text[a:b + 1])
    tags = d.get("tags") or d.get("mots_cles") or []
    if isinstance(tags, str):
        tags = [t for t in re.split(r"[,;\n]", tags)]
    tags = list(dict.fromkeys(str(t).strip().lower().strip(".#") for t in tags if str(t).strip()))[:40]
    return {
        "description": str(d.get("description") or "").strip(),
        "tags": tags,
        "texte": str(d.get("texte") or d.get("text") or "").strip(),
    }


def analyse(cfg: Config, jpeg: bytes, meta: dict) -> dict:
    b64 = base64.b64encode(jpeg).decode()
    prompt = AI_PROMPT + ai_hints(meta)
    if cfg.ai_engine == "ollama":
        body = {
            "model": cfg.ai_model,
            "messages": [{"role": "user", "content": prompt, "images": [b64]}],
            "stream": False,
            "format": AI_SCHEMA,
            "keep_alive": "15m",
            "options": {"temperature": 0.2, "num_ctx": 4096},
        }
        r = requests.post(f"{cfg.ai_url}/api/chat", json=body, timeout=cfg.ai_timeout)
        if r.status_code == 400:  # ancienne version d'Ollama sans schéma JSON
            body["format"] = "json"
            r = requests.post(f"{cfg.ai_url}/api/chat", json=body, timeout=cfg.ai_timeout)
        r.raise_for_status()
        return parse_ai_json(r.json()["message"]["content"])
    # API compatible OpenAI (LM Studio, llama.cpp server, vLLM…)
    body = {
        "model": cfg.ai_model,
        "temperature": 0.2,
        "messages": [{"role": "user", "content": [
            {"type": "text", "text": prompt},
            {"type": "image_url", "image_url": {"url": f"data:image/jpeg;base64,{b64}"}},
        ]}],
    }
    url = cfg.ai_url if cfg.ai_url.endswith("/v1") else cfg.ai_url + "/v1"
    r = requests.post(f"{url}/chat/completions", json=body, timeout=cfg.ai_timeout)
    r.raise_for_status()
    return parse_ai_json(r.json()["choices"][0]["message"]["content"])


# --------------------------------------------------------------------------------------------
# Site o2switch
# --------------------------------------------------------------------------------------------

class Site:
    def __init__(self, cfg: Config):
        self.url = cfg.site_url + "/api.php"
        self.s = requests.Session()
        self.s.headers.update({"X-Api-Token": cfg.api_key, "User-Agent": "photoindex/1.0"})

    def call(self, action: str, data=None, files=None, tries: int = 4) -> dict:
        last = None
        for i in range(tries):
            try:
                r = self.s.post(self.url, params={"action": action}, data=data or {}, files=files, timeout=120)
                try:
                    j = r.json()
                except ValueError:
                    raise RuntimeError(f"réponse inattendue du site (HTTP {r.status_code}) : {r.text[:200]}")
                if r.status_code == 403:
                    sys.exit("Le site refuse la clé API : vérifiez cle_api dans config.ini (page Réglages du site).")
                if r.status_code >= 400:
                    raise RuntimeError(j.get("error") or f"HTTP {r.status_code}")
                return j
            except (requests.ConnectionError, requests.Timeout) as e:
                last = e
                time.sleep(3 * (i + 1))
        raise RuntimeError(f"site injoignable : {last}")

    def raw(self, action: str, data: dict) -> bytes:
        r = self.s.post(self.url, params={"action": action}, data=data, timeout=120)
        if r.status_code >= 400 or not r.headers.get("Content-Type", "").startswith("image/"):
            raise RuntimeError(f"image indisponible (HTTP {r.status_code})")
        return r.content


# --------------------------------------------------------------------------------------------
# Programme principal
# --------------------------------------------------------------------------------------------

@dataclass
class Counters:
    new: int = 0
    updated: int = 0
    meta_only: int = 0
    ai: int = 0
    errors: int = 0
    failed: list[str] = field(default_factory=list)


def root_for(cfg: Config, path: str) -> str:
    np = norm_path(path)
    for r in cfg.roots:
        if np.startswith(norm_path(r) + "/"):
            return r
    return ""


def process(cfg: Config, args, state: State, site: Site, lr: LightroomCatalog) -> None:
    t0 = time.time()
    log(f"Parcours de {', '.join(cfg.roots) if not args.dossier else args.dossier} …")
    files, missing = scan(cfg, args.dossier)
    for m in missing:
        log(f"! Dossier inaccessible : {m}  (NAS éteint ou chemin incorrect ?)")
    log(f"{len(files)} photos trouvées en {time.time() - t0:.0f} s.")
    run_id = int(time.time())
    state.touch([f.path for f in files], run_id)

    # Qu'est-ce qui a changé depuis la dernière fois ?
    todo: list[tuple[FileInfo, str, dict | None]] = []
    for fi in files:
        lrinfo = lr.get(fi.path)
        lrsig = hashlib.md5(json.dumps(lrinfo, sort_keys=True, ensure_ascii=False).encode()).hexdigest()[:10] if lrinfo else ""
        sig = f"{fi.size}|{fi.mtime}|{fi.sidecar_mtime}|{lrsig}|{cfg.preview_sig}|{'ia' if cfg.ai_on and not args.sans_ia else ''}"
        row = state.file(fi.path)
        if row and row[3] == sig and not args.forcer and not args.reanalyser:
            continue
        todo.append((fi, sig, lrinfo))
    if args.limite:
        todo = todo[: args.limite]
    log(f"{len(todo)} photos à traiter (nouvelles ou modifiées).")
    if not todo:
        return

    c = Counters()
    use_ai = cfg.ai_on and not args.sans_ia
    batch = 20
    done = 0
    t_start = time.time()
    for b in range(0, len(todo), batch):
        chunk = todo[b:b + batch]
        ex = run_exiftool(cfg, [fi.path for fi, _, _ in chunk])
        side = run_exiftool(cfg, [fi.sidecar for fi, _, _ in chunk if fi.sidecar])
        for fi, sig, lrinfo in chunk:
            done += 1
            name = os.path.basename(fi.path)
            uid = None
            try:
                exd = ex.get(os.path.normcase(os.path.normpath(fi.path)), {})
                sided = side.get(os.path.normcase(os.path.normpath(fi.sidecar)), {}) if fi.sidecar else {}
                row = state.file(fi.path)
                same_content = row and row[0] == fi.size and row[1] == fi.mtime and row[2]
                uid = row[2] if same_content else fingerprint(fi.path, fi.size)

                meta = build_meta(fi, exd, sided, lrinfo, root_for(cfg, fi.path))
                meta["uid"] = uid

                need_previews = args.forcer or state.previews_sent(uid) != cfg.preview_sig
                cached = state.ai_get(uid)
                need_ai = use_ai and (args.reanalyser or cached is None)
                files_up = {}
                im = None
                if need_previews or need_ai:
                    im = load_image(cfg, fi, exd)
                    if not meta.get("width"):
                        meta["width"], meta["height"] = im.width, im.height
                if need_previews:
                    th = resized(im, cfg.thumb_h * 3, cfg.thumb_h)
                    files_up["thumb"] = ("t.jpg", to_jpeg(th, cfg.quality), "image/jpeg")
                    if cfg.medium > 0:
                        md = resized(im, cfg.medium, cfg.medium)
                        files_up["medium"] = ("m.jpg", to_jpeg(md, cfg.quality), "image/jpeg")
                ai_note = ""
                if need_ai:
                    t_ai = time.time()
                    small = to_jpeg(resized(im, cfg.ai_size, cfg.ai_size), 85)
                    try:
                        res = analyse(cfg, small, meta)
                        state.ai_put(uid, cfg.ai_model, res)
                        cached = (cfg.ai_model, res)
                        c.ai += 1
                        ai_note = f" IA {time.time() - t_ai:.0f}s"
                    except Exception as e:
                        ai_note = " (IA en échec, sera retentée)"
                        log(f"  ! IA : {name} : {e}", also_print=False)
                        sig = sig + "|ia-echec"  # pour retenter au prochain passage
                if cached:
                    model, res = cached
                    meta.update({"ai_description": res.get("description"), "ai_tags": res.get("tags"),
                                 "ai_text": res.get("texte"), "ai_model": model})

                resp = site.call("upsert", data={"meta": json.dumps(meta, ensure_ascii=False, default=str)},
                                 files=files_up or None)
                if files_up:
                    state.mark_sent(uid, cfg.preview_sig)
                state.save_file(fi.path, fi.size, fi.mtime, uid, sig, run_id)
                if resp.get("created"):
                    c.new += 1
                elif files_up:
                    c.updated += 1
                else:
                    c.meta_only += 1

                el = time.time() - t_start
                eta = el / done * (len(todo) - done)
                print(f"[{done}/{len(todo)}] {name}{ai_note}  — reste ~{fmt_dur(eta)}", flush=True)
            except Exception as e:
                c.errors += 1
                c.failed.append(fi.path)
                log(f"[{done}/{len(todo)}] ! {fi.path} : {e}")
                if uid and "miniature manquante" in str(e):
                    state.forget_sent([uid])

    log(f"Terminé en {fmt_dur(time.time() - t0)} : {c.new} nouvelles, {c.updated} mises à jour, "
        f"{c.meta_only} métadonnées seules, {c.ai} analyses IA, {c.errors} erreurs.")
    if c.failed:
        log("Fichiers en erreur (détails dans journal.log) : " + "; ".join(c.failed[:10]) + (" …" if len(c.failed) > 10 else ""))


def fmt_dur(s: float) -> str:
    s = int(s)
    if s < 60:
        return f"{s} s"
    if s < 3600:
        return f"{s // 60} min"
    return f"{s // 3600} h {s % 3600 // 60:02d}"


def cleanup(cfg: Config, args, state: State, site: Site) -> None:
    files, missing = scan(cfg)
    if missing:
        sys.exit("Nettoyage annulé : un dossier est inaccessible (" + ", ".join(missing) + ").")
    if not files:
        sys.exit("Nettoyage annulé : aucune photo trouvée.")
    run_id = int(time.time())
    state.touch([f.path for f in files], run_id)
    local = state.uids_seen(run_id)
    remote = set(site.call("uids").get("uids", []))
    gone = sorted(remote - local)
    log(f"{len(gone)} photos du site n'existent plus sur le NAS.")
    if not gone:
        return
    if len(gone) > 0.2 * len(remote) and not args.forcer:
        sys.exit(f"Par sécurité, rien n'est supprimé ({len(gone)} sur {len(remote)}, plus de 20 %). "
                 "Si c'est normal, relancez avec --nettoyer --forcer.")
    for i in range(0, len(gone), 200):
        site.call("delete", data={"uids": json.dumps(gone[i:i + 200])})
    state.forget_sent(gone)
    state.drop_unseen(run_id)
    log(f"{len(gone)} photos retirées du site.")


def self_test(cfg: Config) -> None:
    ok = True

    def line(good: bool, msg: str):
        nonlocal ok
        ok &= good
        print(("  OK   " if good else "  ÉCHEC ") + msg)

    print("Vérification de la configuration\n")
    for r in cfg.roots:
        line(os.path.isdir(r), f"Dossier photos : {r}")
    if not cfg.roots:
        line(False, "Aucun dossier dans [photos] dossiers")
    try:
        v = subprocess.run([cfg.exiftool, "-ver"], capture_output=True, text=True, timeout=30).stdout.strip()
        line(bool(v), f"exiftool {v}")
    except Exception:
        line(False, "exiftool introuvable (https://exiftool.org, placez exiftool.exe à côté du script)")
    print(f"  {'OK  ' if HEIF_OK else 'info'}  Photos HEIC : {'prises en charge' if HEIF_OK else 'non (pip install pillow-heif si besoin)'}")
    if cfg.ai_on:
        try:
            if cfg.ai_engine == "ollama":
                tags = requests.get(f"{cfg.ai_url}/api/tags", timeout=10).json().get("models", [])
                names = [m.get("name", "") for m in tags]
                has = any(n == cfg.ai_model or n.split(":")[0] == cfg.ai_model or n == cfg.ai_model + ":latest" for n in names)
                line(has, f"Ollama répond, modèle {cfg.ai_model} " + ("installé" if has else f"absent : lancez  ollama pull {cfg.ai_model}"))
            else:
                requests.get(cfg.ai_url, timeout=10)
                line(True, f"Serveur IA joignable : {cfg.ai_url}")
        except Exception as e:
            line(False, f"IA injoignable sur {cfg.ai_url} ({e.__class__.__name__}). Ollama est-il lancé ?")
    else:
        print("  info  IA désactivée")
    if cfg.lr_catalog:
        line(Path(cfg.lr_catalog).exists(), f"Catalogue Lightroom : {cfg.lr_catalog}")
    try:
        j = Site(cfg).call("ping", tries=1)
        line(True, f"Site joignable : {j.get('photos')} photos déjà en ligne")
    except SystemExit as e:
        line(False, str(e))
    except Exception as e:
        line(False, f"Site : {e}")
    print("\nTout est prêt : lancez  python photoindex.py" if ok else "\nCorrigez les points en échec puis relancez le test.")


# --------------------------------------------------------------------------------------------
# Veille : rédaction des textes de publication demandés depuis le téléphone
# --------------------------------------------------------------------------------------------

NET_RULES = {
    "instagram": "Publication Instagram : une accroche forte en première ligne, puis 1 à 3 courts paragraphes, "
                 "quelques emojis bien choisis (pas trop), sauts de ligne aérés.",
    "facebook": "Publication Facebook : ton conversationnel, 2 à 5 phrases, peut inviter les amis à réagir, emojis avec parcimonie.",
    "x": "Publication X / Threads : 270 caractères maximum hashtags compris, percutant.",
    "autre": "Publication pour un réseau social : 2 à 4 phrases.",
}
TONE_RULES = {
    "naturel": "Ton naturel et chaleureux, à la première personne, comme un passionné qui partage sa photo.",
    "poetique": "Ton poétique et évocateur, images et sensations, sans être mièvre.",
    "humour": "Ton léger avec une pointe d'humour, sans forcer.",
    "technique": "Ton de photographe : parle de la lumière, de la composition, et mentionne les réglages (appareil, objectif, focale, ouverture, vitesse, ISO) s'ils sont fournis.",
    "inspirant": "Ton inspirant et positif.",
    "sobre": "Très sobre et court : une ou deux phrases maximum.",
}
HASHTAG_LIMIT = {"instagram": "10 à 15", "facebook": "2 à 4", "x": "1 à 2", "autre": "3 à 6"}


def pub_prompt(job: dict) -> str:
    p = job["photo"]
    net = job.get("network") or "instagram"
    infos = []
    if p.get("taken_at"):
        try:
            d = datetime.strptime(p["taken_at"], "%Y-%m-%d %H:%M:%S")
            mois = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre",
                    "octobre", "novembre", "décembre"][d.month - 1]
            infos.append(f"Date de prise de vue : {d.day} {mois} {d.year}")
        except ValueError:
            pass
    place = ", ".join(x for x in (p.get("location"), p.get("city"), p.get("region"), p.get("country")) if x)
    if place:
        infos.append(f"Lieu : {place}")
    if p.get("title"):
        infos.append(f"Titre donné par le photographe : {p['title']}")
    if p.get("caption"):
        infos.append(f"Légende du photographe : {p['caption']}")
    if p.get("keywords"):
        infos.append("Mots-clés Lightroom : " + ", ".join(p["keywords"][:25]))
    if p.get("ai_description"):
        infos.append(f"Description automatique : {p['ai_description']}")
    if job.get("tone") == "technique":
        reg = " · ".join(str(x) for x in (
            p.get("camera"), p.get("lens"),
            f"{round(float(p['focal']))} mm" if p.get("focal") else None,
            f"f/{p['aperture']:g}" if isinstance(p.get("aperture"), (int, float)) else None,
            f"{p['exposure']} s" if p.get("exposure") else None,
            f"ISO {p['iso']}" if p.get("iso") else None) if x)
        if reg:
            infos.append(f"Réglages : {reg}")
    tags = (f"Termine par une ligne de {HASHTAG_LIMIT.get(net, '5 à 10')} hashtags pertinents "
            "(en français, plus quelques-uns en anglais si utile)." if job.get("hashtags") else "N'ajoute aucun hashtag.")
    consigne = f"\nConsigne particulière du photographe (prioritaire) : {job['instructions']}" if job.get("instructions") else ""
    return (
        "Tu écris pour un photographe amateur qui publie ses propres photos sur les réseaux sociaux.\n"
        "Rédige en français le texte qui accompagnera la photo jointe.\n"
        f"{NET_RULES.get(net, NET_RULES['autre'])}\n{TONE_RULES.get(job.get('tone') or 'naturel', TONE_RULES['naturel'])}\n{tags}"
        f"{consigne}\n\nInformations sur la photo (utilise-les si elles sont utiles, n'invente pas de faits) :\n- "
        + "\n- ".join(infos or ["aucune"])
        + "\n\nRéponds uniquement avec le texte de la publication, prêt à copier-coller, sans guillemets ni commentaire."
    )


def clean_post(t: str) -> str:
    t = t.strip()
    t = re.sub(r"^(voici|here is)[^\n]*:\s*\n", "", t, flags=re.I).strip()
    if len(t) > 2 and t[0] in "«\"“" and t[-1] in "»\"”":
        t = t[1:-1].strip()
    return t


def write_post(cfg: Config, jpeg: bytes, prompt: str) -> str:
    b64 = base64.b64encode(jpeg).decode()
    if cfg.ai_engine == "ollama":
        r = requests.post(f"{cfg.ai_url}/api/chat", timeout=cfg.ai_timeout, json={
            "model": cfg.ai_model, "stream": False, "keep_alive": "15m",
            "messages": [{"role": "user", "content": prompt, "images": [b64]}],
            "options": {"temperature": 0.8, "num_ctx": 4096},
        })
        r.raise_for_status()
        return clean_post(r.json()["message"]["content"])
    url = cfg.ai_url if cfg.ai_url.endswith("/v1") else cfg.ai_url + "/v1"
    r = requests.post(f"{url}/chat/completions", timeout=cfg.ai_timeout, json={
        "model": cfg.ai_model, "temperature": 0.8,
        "messages": [{"role": "user", "content": [
            {"type": "text", "text": prompt},
            {"type": "image_url", "image_url": {"url": f"data:image/jpeg;base64,{b64}"}}]}],
    })
    r.raise_for_status()
    return clean_post(r.json()["choices"][0]["message"]["content"])


def job_image(cfg: Config, site: Site, photo: dict) -> bytes:
    path = photo.get("path") or ""
    if path and os.path.isfile(path):  # l'original sur le NAS, meilleure qualité
        try:
            st = os.stat(path)
            im = load_image(cfg, FileInfo(path, st.st_size, st.st_mtime), {})
            return to_jpeg(resized(im, cfg.ai_size, cfg.ai_size), 88)
        except Exception:
            pass
    data = site.raw("media", {"uid": photo["uid"]})  # sinon l'aperçu stocké sur le site
    im = Image.open(io.BytesIO(data)).convert("RGB")
    return to_jpeg(resized(im, cfg.ai_size, cfg.ai_size), 88)


def watch(cfg: Config, site: Site) -> None:
    log(f"Veille démarrée : j'attends les demandes de texte du site ({cfg.ai_model}). Ctrl+C pour arrêter.")
    last_job = time.time()
    while True:
        try:
            j = site.call("pub_next", data={"model": cfg.ai_model}, tries=2).get("job")
        except SystemExit:
            raise
        except Exception as e:
            print(f"{datetime.now():%H:%M} site injoignable ({e}), nouvel essai dans 60 s", flush=True)
            time.sleep(60)
            continue
        if not j:
            idle = time.time() - last_job
            time.sleep(3 if idle < 600 else 10 if idle < 3600 else 20)
            continue
        last_job = time.time()
        name = j["photo"].get("filename", "")
        log(f"Rédaction n°{j['id']} : {name} ({j.get('network')}, {j.get('tone')})")
        try:
            text = write_post(cfg, job_image(cfg, site, j["photo"]), pub_prompt(j))
            if not text:
                raise RuntimeError("réponse vide de l'IA")
            site.call("pub_done", data={"id": j["id"], "text": text, "model": cfg.ai_model})
            log(f"  ✓ texte envoyé ({len(text)} caractères, {time.time() - last_job:.0f} s)")
        except Exception as e:
            log(f"  ! échec : {e}")
            try:
                site.call("pub_done", data={"id": j["id"], "error": str(e)[:400], "model": cfg.ai_model})
            except Exception:
                pass


def main() -> None:
    ap = argparse.ArgumentParser(description="Indexe les photos du NAS vers le site de recherche.", add_help=False)
    ap.add_argument("--aide", "-h", "--help", action="help", help="affiche cette aide")
    ap.add_argument("--config", default=str(HERE / "config.ini"), help="fichier de configuration")
    ap.add_argument("--test", action="store_true", help="vérifie la configuration sans rien envoyer")
    ap.add_argument("--limite", type=int, default=0, help="ne traite que N photos")
    ap.add_argument("--dossier", help="ne traite que ce sous-dossier")
    ap.add_argument("--sans-ia", action="store_true", help="n'utilise pas l'IA cette fois-ci (plus rapide)")
    ap.add_argument("--reanalyser", action="store_true", help="refait l'analyse IA (ex. après un changement de modèle)")
    ap.add_argument("--forcer", action="store_true", help="renvoie tout, même ce qui n'a pas changé")
    ap.add_argument("--nettoyer", action="store_true", help="retire du site les photos supprimées du NAS")
    ap.add_argument("--veille", action="store_true",
                    help="reste ouvert et rédige les textes de publication demandés depuis le téléphone")
    args = ap.parse_args()

    if sys.platform == "win32":
        try:
            sys.stdout.reconfigure(encoding="utf-8")
        except Exception:
            pass
    cfg = load_config(Path(args.config))
    if args.test:
        self_test(cfg)
        return
    if not cfg.site_url or "mondomaine" in cfg.site_url or cfg.api_key.startswith("COLLER"):
        sys.exit("Renseignez [site] url et cle_api dans config.ini (puis testez avec --test).")
    state = State(STATE_DB)
    site = Site(cfg)
    site.call("ping")
    if args.nettoyer:
        cleanup(cfg, args, state, site)
        return
    if args.veille:
        try:
            watch(cfg, site)
        except KeyboardInterrupt:
            log("Veille arrêtée.")
        return
    lr = LightroomCatalog(cfg)
    try:
        process(cfg, args, state, site, lr)
    except KeyboardInterrupt:
        log("Interrompu. Relancez plus tard : le travail déjà fait est conservé.")


if __name__ == "__main__":
    main()
