# 08. Publishing to Packagist (Step-by-Step Guide)

This guide walks you through publishing **`xivig/captcha`** to [Packagist.org](https://packagist.org), enabling developers worldwide to install it via `composer require xivig/captcha`.

---

## 1. Prerequisites Checklist

Before publishing, ensure the following are in place (already configured in this package):
- [x] Valid `composer.json` with `"name": "xivig/captcha"` (passes `composer validate`).
- [x] Standard `LICENSE` file (MIT).
- [x] Configured `.gitignore` (excludes `vendor/`, `composer.lock`, storage files).
- [x] All 16 automated security tests passing (`php tests/run_tests.php`).

---

## 2. Step 1: Initialize Git Repository & Commit

Open a terminal in the package directory (`E:\0.2027-project\0\xivig-captcha`):

```bash
cd "E:\0.2027-project\0\xivig-captcha"

# Initialize local git repository
git init -b main

# Stage all files
git add .

# Create initial commit
git commit -m "feat: release Xivig Captcha v2.0.0 enterprise anti-abuse engine"

# Create semantic version tag
git tag -a v2.0.0 -m "Release v2.0.0"
```

---

## 3. Step 2: Create a Public GitHub Repository

Packagist does not host your code; it indexes your public Git repository.

1. Log into your GitHub account ([github.com](https://github.com)).
2. Click the **+** (New) button in the top right and select **New repository**.
3. Set the repository name to:
   ```
   xivig-captcha
   ```
4. Set visibility to **Public** *(Packagist requires public repositories unless using private Packagist)*.
5. Leave "Initialize with README", ".gitignore", and "License" **unchecked** (we already created them).
6. Click **Create repository**.

---

## 4. Step 3: Link and Push to GitHub

Link your local repository to GitHub and push your commits and tags:

```bash
# Link the remote (replace <YOUR_GITHUB_USERNAME> with your actual username)
git remote add origin https://github.com/<YOUR_GITHUB_USERNAME>/xivig-captcha.git

# Push code and tags
git push -u origin main --tags
```

---

## 5. Step 4: Submit Package to Packagist

1. Go to [Packagist.org](https://packagist.org).
2. Log in or create an account (you can sign in directly using your GitHub account).
3. Click the **Submit** button in the top navigation bar (or visit [packagist.org/packages/submit](https://packagist.org/packages/submit)).
4. Paste your public GitHub repository URL:
   ```
   https://github.com/<YOUR_GITHUB_USERNAME>/xivig-captcha
   ```
5. Click **Check**. Packagist will clone your repository, validate `composer.json`, and confirm the package name is `xivig/captcha`.
6. Click **Submit** to finalize publication.

Your package is now live on Packagist!

---

## 6. Step 5: Configure Automatic Updates (GitHub Webhook)

To ensure Packagist automatically updates whenever you push a new release tag:

### Option A: Automatic via GitHub OAuth (Recommended)
1. On Packagist, go to **Profile > Settings**.
2. Under **GitHub Integration**, connect your GitHub account.
3. Packagist will automatically configure push webhooks for your repositories.

### Option B: Manual Webhook Setup
1. In Packagist, copy your **API Token** from **Profile > Show API Token**.
2. In your GitHub repository, navigate to **Settings > Webhooks > Add webhook**.
3. Fill in the webhook form:
   - **Payload URL**: `https://packagist.org/api/github?username=<YOUR_PACKAGIST_USERNAME>`
   - **Content type**: `application/json`
   - **Secret**: Paste your Packagist API Token.
   - **Which events**: Select *"Just the push event"*.
   - **Active**: Checked.
4. Click **Add webhook**.

---

## 7. Step 6: Verifying Installation

Any PHP developer can now install your package globally in their projects:

```bash
composer require xivig/captcha
```

Or target the specific release:
```bash
composer require xivig/captcha:^2.0
```
