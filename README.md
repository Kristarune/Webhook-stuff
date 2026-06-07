# Hardcore Demonlist Watcher

Watches `completedlist.gamer.gd/demonlist` every 5 minutes and sends Discord notifications when:
- 🆕 A new demon is added
- ❌ A demon is removed
- ⬆️⬇️ A demon changes position

Runs on **GitHub Actions** — completely free, no server needed.

---

## Setup

### 1. Fork or create this repo (make it Public)

### 2. Add your Discord webhook as a secret
- Go to your repo → **Settings** → **Secrets and variables** → **Actions**
- Click **New repository secret**
- Name: `DISCORD_WEBHOOK`
- Value: `https://discord.com/api/webhooks/YOUR_ID/YOUR_TOKEN`

### 3. Enable Actions
- Go to the **Actions** tab in your repo
- Click **"I understand my workflows, go ahead and enable them"**

### 4. Test it manually
- Go to **Actions** → **Demonlist Watcher** → **Run workflow**

That's it! It will now run automatically every 5 minutes.
