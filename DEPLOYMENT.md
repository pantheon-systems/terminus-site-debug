---
contract_version: 2
kind: tool
service: terminus-site-debug   # a Terminus plugin that downloads and parses a site's logs (`logs:get`, `logs:parse:*`) and summarizes New Relic data (`newrelic:*`). Runs on users' machines and in CI; nothing runs on Pantheon infrastructure

trigger: tag                        # a maintainer pushes a version tag; Packagist lists it. Merging to master releases nothing
merged_implies_deployed: false      # but see the first other_paths_to_prod entry
other_paths_to_prod:
  - "anyone who installs from the git URL (`terminus self:plugin:install git@github.com:pantheon-systems/terminus-site-debug.git`) or copies the repo into ~/.terminus/plugins gets the HEAD of master: Terminus runs `git clone --depth 1` with no ref (src/Commands/Self/Plugin/InstallCommand.php on Terminus 4.x). For them a merge IS the release"
  - "pushing any tag, on any commit or branch, publishes that version on Packagist"
  - "deleting or force-moving a tag changes what Packagist and Composer caches resolve, with no workflow run"
  # GitHub releases exist for every tag (1.0-alpha-1 is flagged a pre-release). Nothing reads releases: Packagist follows tags

pipeline:
  system: github-actions
  workflow: null                    # no release workflow. `.github/workflows/ci.yml` (PHP Compatibility, and a deprecations check on PHP 8.4) run checks only
  jobs_in_order: []
  typical_minutes: null             # n/a: the release is a tag push (seconds) plus Packagist's update
  minutes_by_change: {}
  watch: "Packagist: `curl -s https://repo.packagist.org/p2/pantheon-systems/terminus-site-debug.json` until the new version appears"
  concurrency_group: null
  cancel_in_progress: null
  blocked_by_waiting_run: null
  approval_gate: null               # no GitHub Environment

release:
  mechanism: "a maintainer pushes a version tag on the merged commit of master (`git tag <version> && git push origin <version>`), and (inconsistently) creates a GitHub release for it by hand. Packagist picks the tag up on its own; no workflow step does it"   # inferred: Packagist webhook vs crawl; no release has been observed for this draft
  tag_scheme: "semver, as already used: latest tag 1.1.2"
  never: "move or delete a published tag (Packagist and Composer caches key on it); publish a fixed patch instead"
  procedure: "none written down. TODO(owner): who releases this plugin, and is there a checklist?"

consumers:                          # `gh search code --owner pantheon-systems`, then each hit read; observed 2026-11-10 (search capped at 30 hits per query)
  - {repo: tse-claude-skills, pin: "none (Packagist latest)", file: "analyze-traffic-logs-skills/skills/analyze-traffic-logs/scripts/fetch-logs.sh", picked_up_by: "the analyze-traffic-logs skill needs `terminus logs:get` and tells users to `terminus self:plugin:install pantheon-systems/terminus-site-debug`"}
  - {repo: "Terminus users", pin: "composer constraint chosen at install", picked_up_by: "`terminus self:plugin:install pantheon-systems/terminus-site-debug`, then `self:plugin:update`"}

repo_properties:
  observed_at: 2026-11-10
  trust_level: TL5
  ccb_status: in-ccb
  owner: developer-experience
  repo_type: tool

gating_checks:                      # `gh api repos/pantheon-systems/terminus-site-debug/rules/branches/master`
  required: ["Wiz IaC Scanner", "Wiz Vulnerability Scanner", "Wiz Data Scanner"]   # org ruleset 'TL5'
  approvals: {required: 0, code_owner: false, last_push_approval: false, strict_up_to_date: false, merge_queue: false}   # TL5 - Non-Prod
  run_but_not_required: ["PHP Compatibility", "Check for deprecations", "CodeQL"]
  conditional: []
  release_gate: "none. A tag can be pushed on any commit, including one that never went through review; CCB is the process gate"

environments:
  - {name: packagist, target: "https://packagist.org/packages/pantheon-systems/terminus-site-debug", gates_prod: true}
  - {name: github-release, target: "github.com/pantheon-systems/terminus-site-debug/releases/<tag>", gates_prod: false}   # by hand, informational

artifact:
  image: null
  file: "the tagged source tree, resolved by Composer from Packagist"
  tag_scheme: semver
  tag_mutable: false                # by convention; nothing enforces it
  retention: "tags kept indefinitely"
  version_signal: "`composer show pantheon-systems/terminus-site-debug` in the plugin directory, or the version in `terminus self:plugin:list`"   # inferred
  tag_to_sha: "git rev-list -n1 <tag>"

runtime: []                         # runs on users' machines and in CI

deployed_when:                      # ALL must hold
  - "`git ls-remote --tags origin <version>` returns the merged commit on master"
  - "Packagist lists <version>: `curl -s https://repo.packagist.org/p2/pantheon-systems/terminus-site-debug.json` (the first entry of `versions`)"

health:
  soak_minutes: 0                   # no fleet to watch; health comes from smoke checks now and user reports afterwards
  baseline: "Packagist lists 1.1.2, listed 2026-04-07; the list matches the tags 1.0-alpha-1 to 1.1.2"
  checks:
    - name: packagist has the tag
      kind: command
      run: "curl -s https://repo.packagist.org/p2/pantheon-systems/terminus-site-debug.json | python3 -c \"import json,sys;print(json.load(sys.stdin)['packages']['pantheon-systems/terminus-site-debug'][0]['version'])\""
      healthy: "== <version>"       # run 2026-11-10: returns the latest tag listed above
    - name: plugin installs and loads
      kind: command
      run: "export TERMINUS_PLUGINS_DIR=$(mktemp -d) && terminus self:plugin:install pantheon-systems/terminus-site-debug && terminus list | head -40"
      healthy: "'Installed pantheon-systems/terminus-site-debug' (or the plugin's commands) appear in `terminus list`, exit 0"   # TODO(verify-on-deploy): not run for this draft
    - name: consumers and users
      kind: delegate
      run: "a trial `terminus logs:get` on a test site, and user reports on the repo's issues"
      healthy: "no new regression reports in the first days"
  known_baseline_errors: []
  dashboards: []
  alerts: []

rollback:
  options:
    - when: "a release is broken and a fix will take more than a few hours"
      kind: retract-and-repin
      steps: |
        1. Revert the bad change on master through a PR.
        2. Tag the next patch release from the revert (never reuse or move the bad tag). Packagist lists it; `terminus self:plugin:update` then picks it up.
        3. Users who can't wait reinstall the previous version by pinning a Composer constraint (`composer require pantheon-systems/terminus-site-debug:<previous>` in the plugin's directory under ~/.terminus/plugins).   # inferred: not exercised
        4. Optional, if the bad version is harmful: a Packagist maintainer can delete that version in the Packagist UI. Composer caches and lock files keep it.   # inferred
      time_to_rollback_minutes: 30  # inferred: PR review plus the tag
    - when: otherwise
      kind: roll-forward
      steps: "fix on master and tag the next patch"
      time_to_rollback_minutes: 60  # inferred
  not_restored:
    - "plugins already installed on users' machines and in CI images keep the bad version until updated"
    - "Composer lock files that resolved the bad version"
  one_way_changes: []               # checked: a release changes no server-side state
  currently_blocked: null
  last_exercised: null
  capture_before_merge: "the latest tag: `git ls-remote --tags origin | sort -V | tail -3`, and Packagist's latest: the check under health"
  who_can: ["@pantheon-systems/developer-experience: push tags and merge on master. TODO(owner): who are the Packagist maintainers of pantheon-systems/terminus-site-debug (needed to delete a version)"]
  verify: "Packagist's first listed version is the new patch; `terminus self:plugin:update` on an affected install reports the new version"

escalation:
  slack: "#ask-hosting"
  owner_team: developer-experience
  paged: false                      # inferred: no alerts exist
  issues: "https://github.com/pantheon-systems/terminus-site-debug/issues"

preflight:
  - "gh auth status (write on pantheon-systems/terminus-site-debug to push tags)"
  - "terminus and composer on PATH (for the install smoke check)"

automation_blockers:
  - blocker: "a release is a hand-pushed tag with no written procedure and no check that the tag is on the reviewed head of master"
    clears_when: "a release workflow (or a documented checklist) tags only a merged commit on master, and creates the GitHub release"
  - blocker: "GitHub releases and tags have drifted apart: GitHub releases exist for every tag (1.0-alpha-1 is flagged a pre-release)."
    clears_when: "the release process creates both together"

verified: null                      # TODO(verify-on-deploy): pass 2 fills it from a real release
---

# Deployment

`terminus-site-debug` is a Terminus plugin that downloads and parses a site's logs (`logs:get`, `logs:parse:*`) and summarizes New Relic data (`newrelic:*`). "Deploying" it means publishing a version tag, which Packagist picks up. Users then get it through `terminus self:plugin:install`, `terminus self:plugin:update` or Composer. Nothing runs on Pantheon infrastructure.

Merging to `master` does not release anything for Packagist users. Since 1.1.2 the default branch has only a dev-dependency bump (#30), SHA pinning (#31) and github-terraform metadata commits (7 commits).

## How a change reaches production

1. **PR to `master`.** Org rules for TL5 require the three Wiz scanners; no approval is required (TL5 - Non-Prod). `.github/workflows/ci.yml` (PHP Compatibility, and a deprecations check on PHP 8.4) run on pushes and PRs but don't block a merge, so check they're green.
2. **Merge.** Nothing is published.
3. **Release.** A maintainer tags the merged commit (`git tag <version> && git push origin <version>`) and sometimes creates a GitHub release by hand. There is no release workflow and no written procedure (TODO(owner)). Packagist lists the new version on its own.
4. **Users** pick it up with `terminus self:plugin:update`, or on a fresh install. The latest version listed on Packagist today is 1.1.2, listed 2026-04-07; the list matches the tags 1.0-alpha-1 to 1.1.2.

Other paths to production: anyone installing from the git URL gets the head of `master` (Terminus clones it with `--depth 1`), so for them a merge is a release. Pushing any tag publishes it.

The tse-claude-skills traffic-log skill relies on `logs:get`, so a broken release breaks that skill for support engineers. GitHub releases exist for every tag (1.0-alpha-1 is flagged a pre-release).

## How to confirm it deployed

```bash
# tag points at the merged commit
git ls-remote --tags https://github.com/pantheon-systems/terminus-site-debug.git <version>

# Packagist lists it
curl -s https://repo.packagist.org/p2/pantheon-systems/terminus-site-debug.json \
  | python3 -c "import json,sys;print(json.load(sys.stdin)['packages']['pantheon-systems/terminus-site-debug'][0]['version'])"
```

## How to confirm it's healthy

There is no telemetry, dashboard or alert. Health is the install smoke test (TODO(verify-on-deploy): not run for this draft), a trial `terminus logs:get` on a test site, and user reports on the repo's issues.

## Rollback

Releases are normally fixed forward. Never delete or move a published tag.

1. **Before tagging**, record the latest tag (`git ls-remote --tags origin | sort -V | tail -3`).
2. Revert the bad change on `master` through a PR, and tag the next patch release from it.
3. Users who can't wait pin the previous version with Composer inside their plugin directory. [inferred]
4. If the version is actively harmful, a Packagist maintainer can delete it in the Packagist UI; Composer caches and lock files keep it. [inferred]

**Verify:** Packagist lists the new patch first, and `terminus self:plugin:update` on an affected install reports it.

**What this doesn't restore:** plugins already installed (they keep the bad version until updated), CI images built in the meantime, and Composer lock files that resolved it.

Rollback has never been exercised.

## Escalation

- Owner: `developer-experience` (code owners `@pantheon-systems/developer-experience`).
- Nobody is paged and there are no alerts.
- Issues: https://github.com/pantheon-systems/terminus-site-debug/issues
- Slack: `#ask-hosting`.

## Open owner questions

- TODO(owner): who releases this plugin, and is there a checklist?
- TODO(owner): who are the Packagist maintainers of `pantheon-systems/terminus-site-debug`?

## Suggested pipeline changes

- A release workflow that tags a reviewed commit on `master` and creates the GitHub release together with the tag.
