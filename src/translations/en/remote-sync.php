<?php
/**
 * Remote Sync translations.
 *
 * Keys are dotted identifiers; values are the English source strings.
 * Placeholders use {name} and ICU plural syntax (Craft requires the intl extension).
 */

return [

    // Errors
    'errors.production_refused' => 'Refusing to run in production. Set allow_production to true in config/remote-sync.php if this machine is intentionally a sync source or target.',
    'errors.no_remotes_configured' => 'No remotes are configured. Add one to config/remote-sync.php.',
    'errors.ambiguous_remote' => 'Multiple remotes are configured ({names}). Pass one explicitly or set REMOTE_SYNC_DEFAULT.',
    'errors.confirmation_required' => 'Confirmation required: re-run with --force.',
    'errors.push_not_allowed' => "Push is not allowed for remote [{name}]. Set 'push' => true for it in config/remote-sync.php to enable.",
    'errors.push_scope_required' => 'Specify --database and/or --files when pushing non-interactively.',
    'errors.rsync_missing_on_remote' => 'rsync was not found on [{name}]. Install it there to sync with this remote.',
    'errors.corrupt_snapshot' => 'The downloaded snapshot failed its integrity check — nothing was imported.',
    'errors.failed_import' => 'Import failed: {error}',
    'errors.failed_file_sync' => 'Failed to sync storage/{path}: {error}',
    'errors.failed_local_backup' => 'Failed to create the local backup: {error}',
    'errors.failed_local_snapshot' => 'Failed to create the local snapshot: {error}',
    'errors.failed_remote_snapshot' => 'Failed to create the remote snapshot: {error}',
    'errors.failed_remote_backup' => 'Failed to create the remote backup: {error}',
    'errors.failed_download_snapshot' => 'Failed to download the snapshot: {error}',
    'errors.failed_upload_snapshot' => 'Failed to upload the snapshot: {error}',
    'errors.failed_remote_load' => 'Failed to load the snapshot on the remote: {error}',
    'errors.failed_delete_snapshot' => 'Failed to delete snapshot: {name}',
    'errors.host_key_changed' => 'WARNING: The host key for [{host}] has changed. This could indicate a man-in-the-middle attack. Update your ~/.ssh/known_hosts manually if this change is expected.',
    'errors.host_key_unknown_noninteractive' => 'The authenticity of host [{host}] cannot be verified. Run interactively to review the fingerprint, or run: ssh -o StrictHostKeyChecking=accept-new {ssh_host} exit',
    'errors.host_key_failed' => 'Failed to save the host key.',

    // Warnings
    'warnings.unknown_host' => 'The authenticity of host [{host}] cannot be verified. It is not in your known_hosts file.',
    'warnings.no_paths_configured' => 'No storage paths are configured for file syncing.',
    'warnings.local_path_not_exists' => 'Local path does not exist: {path}',
    'warnings.up_failed' => 'craft up failed — run `craft up` manually.',
    'warnings.remote_up_failed' => 'craft up failed on [{name}] — run it there manually.',
    'warnings.interrupt_cleanup' => 'Received interrupt signal, cleaning up...',
    'warnings.cleanup_failed' => 'Could not clean up {what} ({error}).',

    // Info
    'info.operation_cancelled' => 'Operation cancelled.',
    'info.no_operations_selected' => 'No operations selected.',
    'info.dry_run_done' => 'Dry run — nothing was changed.',
    'info.doctor_hint' => 'Run `craft remote-sync/doctor {name}` for a full diagnosis.',
    'info.host_key_accepted' => 'Host key for [{host}] has been saved.',
    'info.restore_hint' => 'Your previous database was backed up. Restore it with: craft db/restore {path} --drop-all-tables',
    'info.remote_restore_hint' => 'The remote database was backed up first. Restore it on [{name}] with: craft db/restore {path} --drop-all-tables',
    'info.snapshot_kept' => 'The downloaded snapshot was kept for inspection: {path}',
    'info.cleaned_up' => 'Removed {what}.',
    'info.nothing_to_prune' => 'Nothing to prune (keeping the {keep} most recent snapshots).',
    'info.local_prune_header' => 'Local snapshots to delete (keeping the {keep} most recent):',
    'info.remote_prune_header' => 'Snapshots to delete on [{name}] (keeping the {keep} most recent):',
    'info.deleted_local_snapshots' => '{count, plural, =1{Deleted 1 local snapshot.} other{Deleted # local snapshots.}}',
    'info.deleted_remote_snapshots' => '{count, plural, =1{Deleted 1 remote snapshot.} other{Deleted # remote snapshots.}}',

    // Success
    'success.pulled' => 'Pulled {scope} from [{name}].',
    'success.pushed' => 'Pushed {scope} to [{name}].',

    // Spinners
    'spinners.verifying_host' => 'Verifying remote host...',
    'spinners.accepting_host_key' => 'Saving host key...',
    'spinners.probing' => 'Checking [{name}]...',
    'spinners.building_plan' => 'Building sync plan...',

    // Prompts
    'prompts.confirm.pull' => 'Replace your local {scope} with data from [{name}]?',
    'prompts.confirm.push' => 'Push your local {scope} to [{name}]? This will OVERWRITE remote data.',
    'prompts.confirm.prune' => '{count, plural, =1{Delete 1 snapshot?} other{Delete # snapshots?}}',
    'prompts.confirm.typed_yes_suffix' => 'Type "yes" to continue',
    'prompts.confirm.validation' => 'Type "yes" to confirm',
    'prompts.confirm.accept_host_key' => 'Do you want to trust this host [{host}] and continue connecting?',
    'prompts.remote.label' => 'Select remote environment',
    'prompts.operations.pull_label' => 'What would you like to pull?',
    'prompts.operations.push_label' => 'What would you like to push?',
    'prompts.operations.database' => 'Database',
    'prompts.operations.files' => 'Files',
];
