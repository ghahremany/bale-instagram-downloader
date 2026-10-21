#!/usr/bin/env python3
"""Capture documentation screenshots of the running panel (dev tool, not shipped logic)."""
import asyncio
import os
import sys
from playwright.async_api import async_playwright

BASE = os.environ.get("BASE", "http://127.0.0.1:8080")
INSTALL_BASE = os.environ.get("INSTALL_BASE", "http://127.0.0.1:8085")
OUT = os.environ.get("OUT", "docs/screenshots")
USER = os.environ.get("ADMIN_USER", "admin")
PASS = os.environ.get("ADMIN_PASS", "NewStrongPass987")

VIEWPORT = {"width": 1440, "height": 950}


async def main() -> int:
    os.makedirs(OUT, exist_ok=True)
    async with async_playwright() as pw:
        browser = await pw.chromium.launch()
        ctx = await browser.new_context(viewport=VIEWPORT, device_scale_factor=1)
        page = await ctx.new_page()

        # 1) installer (separate, uninstalled copy)
        try:
            await page.goto(f"{INSTALL_BASE}/install.php", wait_until="networkidle")
            await page.screenshot(path=f"{OUT}/01-installer.png", full_page=True)
            print("installer shot ok")
        except Exception as exc:  # noqa: BLE001
            print("installer shot failed:", exc)

        # 2) login
        await page.goto(f"{BASE}/admin/?r=login", wait_until="networkidle")
        await page.screenshot(path=f"{OUT}/02-login.png")
        await page.fill('input[name="username"]', USER)
        await page.fill('input[name="password"]', PASS)
        await page.click('button[type="submit"]')
        await page.wait_for_load_state("networkidle")

        # 3) OTP screen (code is read from the local file in file-delivery mode)
        if "r=otp" in page.url:
            await page.screenshot(path=f"{OUT}/03-otp.png")
            code = ""
            try:
                with open("storage/otp/otp.log", encoding="utf-8") as fh:
                    for line in fh:
                        if "code=" in line:
                            code = line.strip().split("code=")[-1]
            except FileNotFoundError:
                pass
            if code:
                await page.fill('input[name="code"]', code)
                await page.click('button[type="submit"]')
                await page.wait_for_load_state("networkidle")

        # 4) dashboard + key pages
        shots = [
            ("dashboard", "04-dashboard.png"),
            ("users", "05-users.png"),
            ("broadcast", "06-broadcast.png"),
            ("settings&tab=bot", "07-settings.png"),
            ("security", "08-security.png"),
            ("tools", "09-tools.png"),
        ]
        for route, name in shots:
            await page.goto(f"{BASE}/admin/?r={route}", wait_until="networkidle")
            await page.screenshot(path=f"{OUT}/{name}", full_page=route in ("dashboard", "security"))
            print("shot", name)

        await browser.close()
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
