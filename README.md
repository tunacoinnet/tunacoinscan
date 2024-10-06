# TUNACOIN SCAN - Base (w8.io)

TUNACOIN SCAN blockchain explorer based on PHP and SQLite.

## Basic usage
- first run (fast, minimal indexes): `php w8_updater.php`
- indexer (after first run): `php w8_updater.php indexer`
- run in background: `php w8_updater.php`
- rollback (if needed): `php w8_updater.php rollback <block_number>`

Then route all to `index.php` except `static` folder.
