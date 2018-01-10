EPFL Tequila plugin for WordPress

# Features

- "dual" authentication alongside with ordinary WordPress passwords
  (can be toggled from the wp-admin/ UI)
- cluster support (with Tequila's allowed_request_hosts)

This plugin does *not* perform access control; see
https://github.com/epfl-sti/wordpress.plugin.accred to do that in an
EPFL setting.

# Configuration

wp-cli option update plugin:epfl:tequila_allowed_request_hosts "10.1.2.0/24"
