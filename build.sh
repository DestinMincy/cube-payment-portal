#!/bin/bash
#
# Build a distribution-ready ZIP for Cube Payment Portal.
#
# Usage:
#   bash build.sh
#
# Reads .distignore to determine which files/directories to exclude.
# Outputs: cube-payment-portal.zip in the project root.

set -e

PLUGIN_SLUG="cube-payment-portal"
BUILD_DIR="build"
DIST_DIR="$BUILD_DIR/$PLUGIN_SLUG"
ZIP_FILE="$PLUGIN_SLUG.zip"

echo "Building $PLUGIN_SLUG distribution package..."

# Clean previous build.
rm -rf "$BUILD_DIR"
rm -f "$ZIP_FILE"

# Create build directory.
mkdir -p "$DIST_DIR"

# Build rsync exclude list from .distignore.
EXCLUDES=""
if [ -f ".distignore" ]; then
    while IFS= read -r line; do
        # Skip empty lines and comments.
        line="$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        [ -z "$line" ] && continue
        [[ "$line" == \#* ]] && continue
        # Skip negation patterns (e.g., !readme.txt).
        [[ "$line" == \!* ]] && continue
        EXCLUDES="$EXCLUDES --exclude=$line"
    done < ".distignore"
fi

# Always exclude the build directory itself.
EXCLUDES="$EXCLUDES --exclude=build/ --exclude=$ZIP_FILE --exclude=build.sh"

# Copy files using rsync with exclusions.
eval rsync -a $EXCLUDES ./ "$DIST_DIR/"

# Create the zip.
cd "$BUILD_DIR"
zip -r "../$ZIP_FILE" "$PLUGIN_SLUG/" -q
cd ..

# Clean up.
rm -rf "$BUILD_DIR"

# Show result.
SIZE=$(du -h "$ZIP_FILE" | cut -f1)
echo ""
echo "Done! Created $ZIP_FILE ($SIZE)"
echo ""
echo "Contents:"
zipinfo -1 "$ZIP_FILE" | head -20
COUNT=$(zipinfo -1 "$ZIP_FILE" | wc -l)
echo "... ($COUNT files total)"
