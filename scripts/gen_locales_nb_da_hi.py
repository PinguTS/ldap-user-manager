#!/usr/bin/env python3
"""Generate nb, da, hi locale JSON from en.json (API translate, HTML-safe)."""
import json
import re
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / ".pip_target"))
from deep_translator import GoogleTranslator  # noqa: E402

LOCALES = [("nb", "no"), ("da", "da"), ("hi", "hi")]
EN_PATH = ROOT / "www/locales/en.json"
MAINT_EXACT = (
    "# Example: Different role values\n"
    "LDAP_ADMIN_ROLE=administrator\n"
    "LDAP_MAINTAINER_ROLE=maintainer"
)


def mask_ph(s: str):
    ph = []
    def repl(m):
        ph.append(m.group(0))
        return f"⟦{len(ph)-1}⟧"
    return re.sub(r":[a-z_]+", repl, s), ph


def unmask(s: str, ph: list) -> str:
    for i, p in enumerate(ph):
        s = s.replace(f"⟦{i}⟧", p)
    return s


def translate_plain(t: str, tr) -> str:
    if not t or not t.strip():
        return t or ""
    m, ph = mask_ph(t)
    try:
        r = tr.translate(m)
        if not r:
            return t
        return unmask(r, ph)
    except Exception:
        time.sleep(1.0)
        try:
            r = tr.translate(m)
            return unmask(r, ph) if r else t
        except Exception:
            return t


def translate_value(s: str, tr) -> str:
    if s == MAINT_EXACT or "LDAP_ADMIN_ROLE=administrator" in s:
        return MAINT_EXACT
    if "<" not in s:
        return translate_plain(s, tr)
    parts = re.split(r"(<[^>]+>)", s)
    out = []
    for p in parts:
        if p is None or p == "":
            continue
        if p.startswith("<"):
            out.append(p)
        else:
            out.append(translate_plain(p, tr) or p)
    return "".join(out)


def main():
    en = json.loads(EN_PATH.read_text(encoding="utf-8"))
    keys = list(en.keys())
    out_dir = ROOT / "www/locales"
    for code, google_code in LOCALES:
        tr = GoogleTranslator(source="en", target=google_code)
        data = {}
        for i, k in enumerate(keys):
            data[k] = translate_value(en[k], tr)
            if (i + 1) % 15 == 0:
                time.sleep(0.35)
        path = out_dir / f"{code}.json"
        path.write_text(
            json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )
        print(code, len(data), "->", path)
    for code, _ in LOCALES:
        d = json.loads((out_dir / f"{code}.json").read_text(encoding="utf-8"))
        assert set(d) == set(en), code
    print("parity OK")


if __name__ == "__main__":
    main()
