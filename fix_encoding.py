"""
Fix Arabic encoding corruption in PHP files.

The corruption: original UTF-8 Arabic bytes were interpreted as CP1256 (Windows Arabic),
then re-saved as UTF-8 — causing double-encoding / mojibake.

Reversal: decode UTF-8 → encode as CP1256 (gets back original bytes) → decode as UTF-8.

Files that are already correct UTF-8 Arabic will fail the CP1256 encode step
(because proper Arabic Unicode codepoints produce CP1256 bytes that aren't valid UTF-8),
so they are skipped automatically.
"""

import os
import glob
import shutil

ROOT = os.path.dirname(os.path.abspath(__file__))
BACKUP_DIR = os.path.join(ROOT, '_encoding_backup')

def fix_content(raw_bytes):
    """Try to reverse the mojibake. Returns fixed bytes or None if not corrupted."""
    # Strip BOM if present
    bom = b''
    content = raw_bytes
    if raw_bytes.startswith(b'\xef\xbb\xbf'):
        bom = b'\xef\xbb\xbf'
        content = raw_bytes[3:]

    try:
        garbled_text = content.decode('utf-8')
    except UnicodeDecodeError:
        return None  # Not valid UTF-8, skip

    try:
        # Re-encode as CP1256 to get back the original UTF-8 bytes
        original_bytes = garbled_text.encode('cp1256')
    except (UnicodeEncodeError, LookupError):
        return None  # Cannot encode as CP1256 — file is already correct

    try:
        fixed_text = original_bytes.decode('utf-8')
    except UnicodeDecodeError:
        return None  # Result is not valid UTF-8 — file is already correct

    # Verify the fix contains actual Arabic (U+0600–U+06FF)
    has_arabic = any('؀' <= ch <= 'ۿ' for ch in fixed_text)
    if not has_arabic:
        return None  # No Arabic found after fix — probably not a corrupted file

    return bom + original_bytes


def process_files():
    php_files = glob.glob(os.path.join(ROOT, '**', '*.php'), recursive=True)
    fixed = []
    skipped = []

    for filepath in sorted(php_files):
        with open(filepath, 'rb') as f:
            raw = f.read()

        result = fix_content(raw)
        if result is None:
            skipped.append(filepath)
            continue

        # Backup before overwriting
        rel = os.path.relpath(filepath, ROOT)
        backup_path = os.path.join(BACKUP_DIR, rel)
        os.makedirs(os.path.dirname(backup_path), exist_ok=True)
        shutil.copy2(filepath, backup_path)

        with open(filepath, 'wb') as f:
            f.write(result)

        fixed.append(filepath)
        print(f"  FIXED : {rel}")

    print(f"\nDone. Fixed {len(fixed)} file(s), skipped {len(skipped)} file(s).")
    print(f"Backups saved to: {BACKUP_DIR}")


if __name__ == '__main__':
    print(f"Scanning PHP files under: {ROOT}\n")
    os.makedirs(BACKUP_DIR, exist_ok=True)
    process_files()
