# Feature Todo List

## Requested Enhancements

- [x] **Config isolation directory**: Add a `shipphp-config/` (or configurable) folder to hold `shipphp.json`, `.ignore`, `.shipphp/`, and `shipphp-server.php`, with automatic detection for legacy root configs.
- [x] **Push/pull a specific file or folder**: Already supported via `shipphp push [path]` and `shipphp pull [path]`.
- [x] **Server file tree**: Add a `shipphp tree [path]` command to inspect server file locations.
- [x] **Delete specific file or directory with confirmation**: Add a `shipphp delete <path>` command with safety confirmations.
- [x] **Upload a zip and extract on the server**: Add a `shipphp extract <file.zip>` command to extract on the server (zip only).
- [x] **Remote destination for push/pull**: Add explicit remote path mapping (e.g., `shipphp push local/file.php --to=public/file.php`).
- [ ] **Selective server-side file operations**: Add bulk selection helpers for delete/move/copy via CLI prompts.
- [ ] **Archive extraction for non-zip formats**: Add optional support for `.rar` if server extensions permit.

## Notes

- The extraction command intentionally supports `.zip` only, since PHP's core `ZipArchive` is available on most hosts. `.rar` requires optional extensions on the server.
- The config isolation directory is a larger change that will need migration steps to avoid breaking existing installs.
