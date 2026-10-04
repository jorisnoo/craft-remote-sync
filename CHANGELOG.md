# Changelog

## [Unreleased]

## [2.0.1](https://github.com/jorisnoo/craft-remote-sync/releases/tag/2.0.1) (2026-07-09)

### Features

- **pull:** keep snapshots by default, add --discard-snapshot flag ([d446b52](https://github.com/jorisnoo/craft-remote-sync/commit/d446b52e20ff9cd9d1081be225a24b59add3abd1))
- stream verbose rsync output and add an arch test ([59f1411](https://github.com/jorisnoo/craft-remote-sync/commit/59f14117059de92bf2d5c98234ec7a491089cf59))
- add prune and doctor commands, remove the legacy service ([3e3fc30](https://github.com/jorisnoo/craft-remote-sync/commit/3e3fc30be477eed745a534edf5bce21036b691f2))
- add the redesigned push command with a hard push gate ([a033abb](https://github.com/jorisnoo/craft-remote-sync/commit/a033abbdb28d69085eaeef527e329db2ad9a9d23))
- add planner, plan preview, and the redesigned pull command ([fe2edfd](https://github.com/jorisnoo/craft-remote-sync/commit/fe2edfd862d605010f3c423bfdf19ca056729fef))
- add rsync builder, snapshot lifecycle, and support utilities ([bf3da57](https://github.com/jorisnoo/craft-remote-sync/commit/bf3da57c0288957886457f77c81d67f95594869b))
- add Connection transport and single-round-trip Probe ([9bc7c3e](https://github.com/jorisnoo/craft-remote-sync/commit/9bc7c3e66f0be93738879cc6be52bec019752574))
- auto-detect compatible PHP version on remote servers ([c50293c](https://github.com/jorisnoo/craft-remote-sync/commit/c50293cf58107d104c9726d27855d843453b182b))

### Bug Fixes

- harden interrupt cleanup, config merge, backups, and dependencies ([5d01419](https://github.com/jorisnoo/craft-remote-sync/commit/5d014192fedfdbf63c6e4160723f8ff61f96cf67))

### Code Refactoring

- add config schema, remote DTOs, registry, and process seam ([442a9ed](https://github.com/jorisnoo/craft-remote-sync/commit/442a9ed9e838aed4a324241ff144d1bc406486bd))

### Chores

- tidy metadata, add LICENSE.md and justfile ([8d13cf2](https://github.com/jorisnoo/craft-remote-sync/commit/8d13cf24fa7fd624a5ad1e39a9b08a56d4085e7c))
## [2.0.0](https://github.com/jorisnoo/craft-remote-sync/releases/tag/2.0.0) (2026-05-12)

### Features

- sanitize database backups by stripping MariaDB sandbox headers ([d02fbb0](https://github.com/jorisnoo/craft-remote-sync/commit/d02fbb0d2acb53e20dae4dbc78655f06e4fdf1b2))
- add --progress flag to rsync operations ([0bdaf7b](https://github.com/jorisnoo/craft-remote-sync/commit/0bdaf7baf0e4445932fb6357226cd6d0d32b97d2))
- add dependabot auto-merge workflow and refactor backup sync methods ([fb6efad](https://github.com/jorisnoo/craft-remote-sync/commit/fb6efad3954169b8463cbeea7d9108c5c4f7216c))
- register module alias ([ffcd45d](https://github.com/jorisnoo/craft-remote-sync/commit/ffcd45d2b7fe9f401858f0c388db88a42d88ddf5))
- add CI test workflow and basic unit tests ([a51f08e](https://github.com/jorisnoo/craft-remote-sync/commit/a51f08e733f6eb0277f7f9e36bf077134f47c9e1))
- **push:** add CLI options for remote host, path, and force flag ([897dffd](https://github.com/jorisnoo/craft-remote-sync/commit/897dffd51b358824165e77697169198f04c020d6))
- require double confirmation for pull and push both operations ([5f617ae](https://github.com/jorisnoo/craft-remote-sync/commit/5f617ae95ebda905d992281dd6c7920c290a63b5))
- add confirmation prompt before creating remote backup on push ([85fc60e](https://github.com/jorisnoo/craft-remote-sync/commit/85fc60e39b57c859d03d9aa85406f29784dccf47))
- exclude craft-transforms from rsync by default ([a91f604](https://github.com/jorisnoo/craft-remote-sync/commit/a91f604d65bf0456784097ad5a5617c21fd2caa2))
- display rsync output summary after sync operations ([8effe27](https://github.com/jorisnoo/craft-remote-sync/commit/8effe27e084929affae4aab9b9ebe642a0aebb9a))
- show selected remote name when only one remote is available ([cd00f69](https://github.com/jorisnoo/craft-remote-sync/commit/cd00f69b225987a127b7d1e5742eb7441d89a799))
- exclude dotfiles from rsync and fix confirm prompt labels ([a019db2](https://github.com/jorisnoo/craft-remote-sync/commit/a019db2d856494f16cd48929e8007ded57f61d1e))
- filter push-disallowed remotes from push command picker ([2c50621](https://github.com/jorisnoo/craft-remote-sync/commit/2c506212c8e871e6a9c2f8c69568dcedbbfeaa72))
- add Laravel Prompts TUI to pull & push commands ([1eb8a20](https://github.com/jorisnoo/craft-remote-sync/commit/1eb8a20ce472335947027cbedf9f2d523e72c5bb))
- [US-008] - Branch for Craft 5 ([f483292](https://github.com/jorisnoo/craft-remote-sync/commit/f4832920fab7272d6c67fc3e039aa490aca08bd7))

### Bug Fixes

- **backup:** support both MariaDB sandbox header variants ([40b5477](https://github.com/jorisnoo/craft-remote-sync/commit/40b54773867cb2699043868c38bf47b04de480cb))
- use distinct exit code for user abort to prevent early pipeline termination ([e897bda](https://github.com/jorisnoo/craft-remote-sync/commit/e897bdacbf97bd9efa88a6c57ec95f71636a8e9c))

### Code Refactoring

- simplify backup terminology by removing "safety" prefix ([517f93f](https://github.com/jorisnoo/craft-remote-sync/commit/517f93ffcc57bbe855ec1f1be90ae9151d8e7fc0))
- consolidate process error formatting ([416ce5f](https://github.com/jorisnoo/craft-remote-sync/commit/416ce5fdcb3aab1d46689244b39202a5577b1447))
- consolidate remote selection and operation handling with optional remote parameter ([2c47047](https://github.com/jorisnoo/craft-remote-sync/commit/2c47047a019bfb8b936a9f0a4d933d664546d368))
- remove default remote configuration ([f3b546c](https://github.com/jorisnoo/craft-remote-sync/commit/f3b546cc5f8651261956ccfe6cdcc4e26652b557))
- remove default configuration from config.php ([eba4146](https://github.com/jorisnoo/craft-remote-sync/commit/eba4146138beefb28016a1675d968f101c3cb651))
- simplify dependabot auto-merge workflow ([9b87c91](https://github.com/jorisnoo/craft-remote-sync/commit/9b87c913a1f462e1a93763876e06abfd9d3dfc00))
- extract file preview method and cache configuration ([790efc9](https://github.com/jorisnoo/craft-remote-sync/commit/790efc9718c0c1cac126bab0f847cb2f941d4f10))
- rename namespace from jorge\craftremotesync to Noo\CraftRemoteSync ([fb9761a](https://github.com/jorisnoo/craft-remote-sync/commit/fb9761a18bc30eb8bd89c8408065ad90bf076b40))
- convert plugin to Yii2 module ([f30bcdf](https://github.com/jorisnoo/craft-remote-sync/commit/f30bcdf42e337252f64316eee2956c7e247b94c5))

### Documentation

- fix remaining plugin→module reference in README ([303bc84](https://github.com/jorisnoo/craft-remote-sync/commit/303bc8470a67192c0236b6c86a64596fe812b23c))
- restore badges in README ([be79349](https://github.com/jorisnoo/craft-remote-sync/commit/be7934958b3562784e2870fb012fa81723ec4302))
- add README, gitignore, and plugin metadata to composer.json ([dcfbc4e](https://github.com/jorisnoo/craft-remote-sync/commit/dcfbc4ebefacdd511bcf8472e6490485e53f8bc0))

### Chores

- add dependabot configuration and update base module import ([8b17298](https://github.com/jorisnoo/craft-remote-sync/commit/8b1729888ec2dbfdb293c078dad74bef2e6484e0))
- update PRD and progress for US-008 ([9e99493](https://github.com/jorisnoo/craft-remote-sync/commit/9e99493dcd7819e1f569973d3d67bf5eeb221d41))
## [1.2.1](https://github.com/jorisnoo/craft-remote-sync/releases/tag/1.2.1) (2026-03-16)

### Features

- **pull:** require double confirmation for production operations ([9fcc723](https://github.com/jorisnoo/craft-remote-sync/commit/9fcc723bb27d87da04b29f00a57ed90e4e1b1f2b))
## [1.2.0](https://github.com/jorisnoo/craft-remote-sync/releases/tag/1.2.0) (2026-03-10)

### Features

- verify SSH host key before remote operations with interactive fingerprint acceptance ([b41fd85](https://github.com/jorisnoo/craft-remote-sync/commit/b41fd85655143af5dd9c00127a9156c5d1252330))
## [1.1.2](https://github.com/jorisnoo/craft-remote-sync/releases/tag/1.1.2) (2026-03-10)

### Features

- allow production environment commands with user confirmation instead of blocking ([ce59938](https://github.com/jorisnoo/craft-remote-sync/commit/ce59938622585aba0f74c1452a959b3b010abc6f))
- clean up remote backups on SIGINT interruption during push/pull operations ([7405004](https://github.com/jorisnoo/craft-remote-sync/commit/7405004d1a6eb14da5850d7667c2d064f2be3df6))
- stream download backup output to stdout instead of using spinner ([346a6f2](https://github.com/jorisnoo/craft-remote-sync/commit/346a6f233f68f9bba4e5853cfaeaab9265cbb601))

### Code Refactoring

- extract verbose mode with streaming output support into InteractsWithRemote trait ([9a1ef01](https://github.com/jorisnoo/craft-remote-sync/commit/9a1ef011add35aa6d5b9750637e97999105ad2eb))
## [1.1.1](https://github.com/jorisnoo/craft-remote-sync/releases/tag/1.1.1) (2026-03-09)

### Bug Fixes

- set module basePath before parent initialization ([7c37738](https://github.com/jorisnoo/craft-remote-sync/commit/7c377387c79d4954051020798f7ec7fa383cef7a))
- exclude current environment from remote selection options ([e821857](https://github.com/jorisnoo/craft-remote-sync/commit/e821857f5e1e8616d628205749cb77fb9b0daed3))
## [1.1.0](https://github.com/jorisnoo/craft-remote-sync/releases/tag/1.1.0) (2026-03-09)

### Features

- show selected remote name in confirmation prompts ([3457d3a](https://github.com/jorisnoo/craft-remote-sync/commit/3457d3a64711ff412894db9332805c0ca6919b83))
- add confirmation prompt before creating remote backup on push ([2cc0d5f](https://github.com/jorisnoo/craft-remote-sync/commit/2cc0d5f658ea4a584a0d86b2d7153b45f79701a3))

### Code Refactoring

- always show remote selection prompt instead of auto-selecting single remote ([332a632](https://github.com/jorisnoo/craft-remote-sync/commit/332a63245c528c2a6aacdeefa965fd8a4fa65ae5))
## [1.0.0](https://github.com/jorisnoo/craft-remote-sync/releases/tag/1.0.0) (2026-02-28)

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
