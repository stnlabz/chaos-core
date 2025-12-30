Chaos Core Updater

Core update scope: /app/**
Public scope: untouched

Flags:
- /app/data/update.lock
- /app/data/maintenance.flag

Commands:
- php /app/update/run.php status
- php /app/update/run.php lock
- php /app/update/run.php unlock
- php /app/update/run.php maintenance:on
- php /app/update/run.php maintenance:off
- php /app/update/run.php apply --file=... --sha256=...
- php /app/update/run.php rollback --from=...

Package format must contain an app/ root directory.

