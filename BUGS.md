# BUGS.md — Phase 9 Known Issues

## Known Issues — Phase 9

### Fixed
- [0.9.1] accounts/log fatal error — log() conflicts with PHP built-in math function. Fixed: renamed to accounts_log().

### Open
- Forgot Password set success screen briefly shows "This link has expired or already used" after successful first-time setup. Needs investigation.
- nginx subdirectory install requires if/rewrite pattern — PATH_INFO empty with try_files approach. Fixed in nginx.conf.sample and INSTALL.md but root cause in index.php PATH_INFO fallback should be reviewed.
