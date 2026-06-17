#!/usr/bin/env python3
"""Build install.sh from index.php"""
import base64, gzip, re, sys, os

WORKDIR = os.path.dirname(os.path.abspath(__file__))
INDEX_PATH = os.path.join(WORKDIR, 'index.php')
INSTALL_CORE_PATH = os.path.join(WORKDIR, 'install-core.sh')
INSTALL_SH_PATH = os.path.join(WORKDIR, 'install.sh')

# Step 1: Read LOCAL install-core.sh (the canonical source)
print(f"📄 Reading local install-core.sh...")
with open(INSTALL_CORE_PATH, 'r') as f:
    core_sh = f.read()

print(f"   install-core.sh: {len(core_sh.split(chr(10)))} lines, {len(core_sh):,} bytes")

# Step 3: Read updated index.php
with open(INDEX_PATH, 'r') as f:
    index_php = f.read()
print(f"📄 index.php (new): {len(index_php.split(chr(10)))} lines, {len(index_php):,} bytes")

# Step 4: Gzip + base64 encode index.php
index_gz = gzip.compress(index_php.encode('utf-8'))
index_b64 = base64.b64encode(index_gz).decode('utf-8')
print(f"🗜️  index.php encoded: {len(index_b64)} chars")

# Step 5: Replace the base64 block in install-core.sh
# Find pattern: echo "BASE64..." | base64 -d | gunzip > /var/www/manager/index.php
pattern = r'echo "([A-Za-z0-9+/=]+)" \| base64 -d \| gunzip > /var/www/manager/index\.php'
match = re.search(pattern, core_sh)
if not match:
    print("ERROR: Cannot find index.php base64 block in install-core.sh")
    # Debug: show around line with index.php
    for i, line in enumerate(core_sh.split('\n')):
        if 'index.php' in line:
            print(f"   Line {i+1}: {line[:100]}...")
    sys.exit(1)

old_b64 = match.group(1)
core_sh_updated = core_sh.replace(old_b64, index_b64)

# Save install-core.sh
with open(INSTALL_CORE_PATH, 'w') as f:
    f.write(core_sh_updated)
print(f"💾 install-core.sh saved: {len(core_sh_updated.split(chr(10)))} lines, {len(core_sh_updated):,} bytes")

# Step 6: Gzip + base64 encode install-core.sh
core_gz_new = gzip.compress(core_sh_updated.encode('utf-8'))
core_b64_new = base64.b64encode(core_gz_new).decode('utf-8')
print(f"🗜️  install-core.sh encoded: {len(core_b64_new)} chars")

# Step 7: Create install.sh (2-line wrapper)
install_sh_content = f"#!/bin/bash\necho \"{core_b64_new}\" | base64 -d | gunzip | bash\n"
with open(INSTALL_SH_PATH, 'w') as f:
    f.write(install_sh_content)
os.chmod(INSTALL_SH_PATH, 0o755)

print(f"✅ install.sh created: {len(install_sh_content):,} bytes")
print(f"✅ Build complete!")
