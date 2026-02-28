# Changelog

## [Unreleased]

## [1.0.0](https://github.com/jorisnoo/craft-remote-sync/releases/tag/v1.0.0) (2026-02-28)

### Features

- stream rsync output in real-time and add release workflow with changelog tooling ([eefceac](https://github.com/jorisnoo/craft-remote-sync/commit/eefceac20a5a12dcca41b49b9a9d6fb0627524a4))
- exclude craft-transforms from rsync by default ([0d071d2](https://github.com/jorisnoo/craft-remote-sync/commit/0d071d2a7a68cae2a4e87faa92226d729cae8e86))
- display rsync output summary after sync operations ([a0aa01d](https://github.com/jorisnoo/craft-remote-sync/commit/a0aa01d2aad62a2d6bd97389d8c69d30e969a164))
- show selected remote name when only one remote is available ([0974dbd](https://github.com/jorisnoo/craft-remote-sync/commit/0974dbdbe1b27bbeaed1df99ff3611d5777f0375))
- exclude dotfiles from rsync and fix confirm prompt labels ([812e7ed](https://github.com/jorisnoo/craft-remote-sync/commit/812e7eddb2ee263a318679716a689d80375f1b30))
- filter push-disallowed remotes from push command picker ([8d17f61](https://github.com/jorisnoo/craft-remote-sync/commit/8d17f61333f95249d9bbfd7d15c174c3a6bde72e))
- [US-007] - Implement push command ([39f7bd2](https://github.com/jorisnoo/craft-remote-sync/commit/39f7bd2236e94b15e6fb1107fa61ed66b812f962))
- [US-006] - Implement pull command ([0ed27a2](https://github.com/jorisnoo/craft-remote-sync/commit/0ed27a2b0abb09929b943f613f0df30b5eeb2011))
- [US-005] - Create InteractsWithRemote trait ([ed25210](https://github.com/jorisnoo/craft-remote-sync/commit/ed25210bd9f9d2725af8a8d55cd76dd49af06a66))
- [US-003] - Create RemoteSyncService ([0b02f91](https://github.com/jorisnoo/craft-remote-sync/commit/0b02f918709b92b9f55a00a40cecd5fabd34557d))
- [US-002] - Create configuration system ([1c9847b](https://github.com/jorisnoo/craft-remote-sync/commit/1c9847b4bf47282b56ccbc37196a8274f0e621a0))
- [US-001] - Scaffold plugin structure ([6f8d18c](https://github.com/jorisnoo/craft-remote-sync/commit/6f8d18ceb09f0363c4798ce03f72c19d751a36aa))

### Bug Fixes

- use distinct exit code for user abort to prevent early pipeline termination ([eee017d](https://github.com/jorisnoo/craft-remote-sync/commit/eee017d8d5aefce8da40523913aa6744d876c7ff))
- module import ([e854075](https://github.com/jorisnoo/craft-remote-sync/commit/e854075a7e68b0fd78856a5496fd9f2b0c7b351d))

### Code Refactoring

- replace stdout/stderr output with Laravel Prompts UI components ([fa3c7b6](https://github.com/jorisnoo/craft-remote-sync/commit/fa3c7b6f47216df8573f816ea0d099a7d18b600b))
- rename PHP namespace from jorge\craftremotesync to Noo\CraftRemoteSync ([0ed126b](https://github.com/jorisnoo/craft-remote-sync/commit/0ed126bb77bb3105f46c59b608cf05eaa12fcf9d))
- convert plugin to Yii2 module ([a622f6a](https://github.com/jorisnoo/craft-remote-sync/commit/a622f6ab7827505789c5d84e319f552ddc078d56))

### Documentation

- improve requirements table formatting in README ([ead6e69](https://github.com/jorisnoo/craft-remote-sync/commit/ead6e699eb472b862a524faa7ce9877a1cf57919))

### Chores

- add .gitignore ([3117bb9](https://github.com/jorisnoo/craft-remote-sync/commit/3117bb96c94e11f41b571379113b9eb98fce8ad8))
- update PRD and progress for US-007 ([4e6d11b](https://github.com/jorisnoo/craft-remote-sync/commit/4e6d11b4817c09d260a56ce9d17ec85b5902918f))
- update progress.md for US-006 ([0b964f5](https://github.com/jorisnoo/craft-remote-sync/commit/0b964f5d653b01ba12475e505c9a8a5f4d8501d2))
- update PRD and progress for US-005 ([ea3fc55](https://github.com/jorisnoo/craft-remote-sync/commit/ea3fc5502211feab7ba7335c62b6683cf6f87b5a))
- update PRD and progress for US-003 and US-004 ([6b3160a](https://github.com/jorisnoo/craft-remote-sync/commit/6b3160aaf197befddfbb5f04b756c6b29e141b20))
- update progress.md for US-002 ([90a5ad6](https://github.com/jorisnoo/craft-remote-sync/commit/90a5ad6aadcaf58454f4d9d925f8c65e6f9c8684))
- mark US-002 as passing in PRD ([fdb700a](https://github.com/jorisnoo/craft-remote-sync/commit/fdb700a9fff25e26c5b801fe5c2d2c34ad46f1c1))
- Initial release
