# Setting Up ngrok for Square OAuth Testing

Since Square OAuth may have issues with `localhost` redirect URLs, you can use **ngrok** to create a public HTTPS tunnel.

## Quick Setup

### 1. Install ngrok

Download from: https://ngrok.com/download

Or via Chocolatey:
```powershell
choco install ngrok
```

### 2. Start the tunnel

With Docker running, open a new terminal and run:
```bash
ngrok http 8080
```

This gives you a public URL like: `https://abc123.ngrok-free.app`

### 3. Update Square Developer Dashboard

1. Go to https://developer.squareup.com/apps
2. Select your app → **OAuth** tab
3. Add the ngrok URL as a redirect:
   ```
   https://abc123.ngrok-free.app/wp-admin/admin.php?page=spp-settings
   ```

### 4. Update WordPress Site URL (temporary)

In WordPress admin:
1. Go to **Settings → General**
2. Change both URLs to your ngrok URL:
   - WordPress Address: `https://abc123.ngrok-free.app`
   - Site Address: `https://abc123.ngrok-free.app`
3. Save

### 5. Access WordPress via ngrok

Now access: `https://abc123.ngrok-free.app/wp-admin/`

The OAuth redirect should work!

---

## Reverting After Testing

When done testing OAuth:
1. Stop ngrok (Ctrl+C)
2. Change WordPress URLs back to `http://localhost:8080`
3. Or restart Docker: `docker-compose down && docker-compose up -d`
