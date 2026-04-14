# This is a utility to call the db_download_latest_dump.php
# and only if it runs successfully follow it with db_restore_latest_dump.sh

if php db_download_latest_dump.php; then
    ./db_restore_latest_dump.sh
fi