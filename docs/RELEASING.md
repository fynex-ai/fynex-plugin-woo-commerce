# Releasing

## Version string

One version string, in five places, all equal:

1. `Version:` in the `fynex-for-woocommerce.php` header
2. `FYNEX_WC_VERSION` in the same file
3. `Stable tag:` in `readme.txt`
4. the top entry of `changelog.txt` (`YYYY-MM-DD - version X.Y.Z`)
5. the Git tag `vX.Y.Z` and the GitHub release zip

Bump it last, in the release pull request, so the number is not used up on an
intermediate build.

## Before tagging

- [ ] CI is green: coding standards, PHPUnit on the oldest and newest supported
      PHP/WordPress/WooCommerce, both order storages.
- [ ] `WC tested up to` (plugin header) and `Tested up to` (readme.txt) name the
      current WooCommerce and WordPress releases.
- [ ] `languages/fynex-for-woocommerce.pot` regenerated if strings changed:
      `wp i18n make-pot . languages/fynex-for-woocommerce.pot --slug=fynex-for-woocommerce --domain=fynex-for-woocommerce --exclude=vendor,tests,dist,bin`
- [ ] Plugin Check passes on the zip (`wp plugin check fynex-for-woocommerce`).
- [ ] Manual pass of `docs/TESTING.md` against a Fynex test key.

## WordPress.org

**First submission (once).** The company's WordPress.org account uploads the zip at
<https://wordpress.org/plugins/developers/add/>. The slug comes from the plugin name, so
it will be `fynex-for-woocommerce`, which matches the text domain. Before uploading, put
that account's username in `Contributors:` in `readme.txt`. It currently says `fynex`, which is
a placeholder: a WordPress.org user named `fynex` already exists (joined January 2024) and it is
not confirmed to be ours, so replace it before submitting. The slug `fynex-for-woocommerce` was
still free on 2026-10-12.
Review takes from days to weeks; replies come by email to the account owner.

**Every release after approval.** WordPress.org serves the plugin from SVN, not from Git:

```sh
./bin/package
svn checkout https://plugins.svn.wordpress.org/fynex-for-woocommerce svn
rm -rf svn/trunk/* && unzip -q dist/fynex-for-woocommerce.zip -d /tmp/ffw && cp -R /tmp/ffw/fynex-for-woocommerce/. svn/trunk/
cp .wordpress-org/* svn/assets/          # icon, banner and screenshots for the directory page
svn cp svn/trunk svn/tags/X.Y.Z
cd svn && svn add --force . && svn commit -m "Release X.Y.Z"
```

The directory page shows the version named by `Stable tag` in `trunk/readme.txt`, so tag first and
bump `Stable tag` in the same commit.

## QIT (Woo Marketplace)

The Marketplace listing needs an approved Woo vendor account and, for a free payment gateway, a
partnership agreement with Woo (see the readiness audit in fynex-website). Until both exist this
section is preparation only.

Woo runs QIT on every Marketplace submission and update; **activation, security and
malware must pass** for a version to deploy. Once the vendor account exists:

```sh
composer global require woocommerce/qit-cli
qit partner:add          # once, with the Woo partner user and application password
./bin/package
qit run:activation fynex-for-woocommerce --zip=dist/fynex-for-woocommerce.zip
qit run:security fynex-for-woocommerce --zip=dist/fynex-for-woocommerce.zip
qit run:malware fynex-for-woocommerce --zip=dist/fynex-for-woocommerce.zip
qit run:phpcompatibility fynex-for-woocommerce --zip=dist/fynex-for-woocommerce.zip
```

Check the exact commands against <https://qit.woo.com/docs/> when the account is
set up, then move this into CI with the partner credentials as repository secrets.

## Build

```sh
./bin/package
```

`dist/fynex-for-woocommerce.zip` contains only runtime files: the main file,
`uninstall.php`, `includes/`, `assets/`, `languages/`, `readme.txt`,
`changelog.txt`, `README.md` and `LICENSE`.
