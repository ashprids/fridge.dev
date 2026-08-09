#!/usr/bin/env bash
set -euo pipefail

: "${TARGET:?TARGET must be set}"

runtime_user="http"
shared_group="http"
deploy_user="$(id -un)"
data_dir="$TARGET/data"

if [[ "$TARGET" != "/var/www/fridge.dev" ]]; then
    echo "refusing to change permissions for unexpected target: $TARGET" >&2
    exit 1
fi

[[ -d "$TARGET" ]] || { echo "deploy target does not exist: $TARGET" >&2; exit 1; }
[[ -d "$data_dir" ]] || { echo "data directory does not exist: $data_dir" >&2; exit 1; }
command -v setfacl >/dev/null 2>&1 || {
    echo "setfacl is required on the server (install the Arch Linux acl package)" >&2
    exit 1
}
id -nG "$deploy_user" | tr ' ' '\n' | grep -Fxq "$shared_group" || {
    echo "$deploy_user must belong to the $shared_group group" >&2
    exit 1
}
sudo -n -u "$runtime_user" true || {
    echo "$deploy_user needs passwordless sudo access as $runtime_user" >&2
    exit 1
}

# Deployed application files are owned by deploy and readable by http. Setgid
# and default ACLs keep that relationship for files introduced by later rsyncs.
while IFS= read -r -d '' path; do
    chgrp "$shared_group" "$path"
    chmod u=rwx,g=rx,o= "$path"
    chmod g+s "$path"
    setfacl -m u::rwx,g::r-x,g:"$shared_group":r-x,m::r-x,o::--- "$path"
    setfacl -d -m u::rwx,g::r-x,g:"$shared_group":r-x,m::r-x,o::--- "$path"
done < <(find "$TARGET" -path "$data_dir" -prune -o -type d -user "$deploy_user" -print0)

while IFS= read -r -d '' path; do
    chgrp "$shared_group" "$path"
    if [[ -x "$path" ]]; then
        chmod u=rwx,g=rx,o= "$path"
    else
        chmod u=rw,g=r,o= "$path"
    fi
    setfacl -m u::rw-,g::r--,g:"$shared_group":r--,m::r--,o::--- "$path"
done < <(find "$TARGET" -path "$data_dir" -prune -o -type f -user "$deploy_user" -print0)

# Runtime data is shared read/write between http and deploy. Existing files are
# normalized and default ACLs make newly-created paths inherit the same access.
sudo -n -u "$runtime_user" find "$data_dir" -type d -exec chmod 2770 {} +
sudo -n -u "$runtime_user" find "$data_dir" -type f -exec chmod 0660 {} +
sudo -n -u "$runtime_user" setfacl -R -m u::rwX,g::rwX,g:"$shared_group":rwX,m::rwX,o::--- "$data_dir"
sudo -n -u "$runtime_user" find "$data_dir" -type d -exec \
    setfacl -d -m u::rwx,g::rwx,g:"$shared_group":rwx,m::rwx,o::--- {} +

# sitemap.xml is runtime-generated but sits outside data.
if [[ -e "$TARGET/sitemap.xml" ]]; then
    sudo -n -u "$runtime_user" chmod 0660 "$TARGET/sitemap.xml"
    sudo -n -u "$runtime_user" setfacl -m \
        u::rw-,g::rw-,g:"$shared_group":rw-,m::rw-,o::--- "$TARGET/sitemap.xml"
fi

echo "Production permissions and inherited ACLs are ready."
