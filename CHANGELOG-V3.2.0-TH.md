# AVSF B-NTP v3.2.0 — Clean Site Package & Safe Reset

วันที่: 8 พฤศจิกายน 2026

## เพิ่มระบบ Clean Site Package ใน Backup & Restore Center
- สร้าง ZIP สำหรับติดตั้ง AVSF เป็นเว็บไซต์ใหม่ได้จากหน้า Backup & Restore โดยไม่แก้ไขเว็บต้นทาง
- ZIP มี Source, Admin, Site Center, Frontend, Installer, CSS/JS, i18n และไฟล์ `.htaccess` ของระบบ
- รวม `database-schema.sql` และ `default-settings.json` ที่สร้างจากค่าตั้งต้นกลางชุดเดียวกับ Auto Installer
- ไม่รวม SQL dump ของเว็บเดิม, `config.local.php`, `.env`, ผู้ใช้/Password Hash/2FA Secret, ข้อมูลเนื้อหา, uploads, cache, logs, backup history, API/SMTP secrets
- คำแนะนำใต้ช่องตั้งค่า, labels, placeholders, SEO guidance และภาษาไทย/อังกฤษยังอยู่ครบเพราะเป็นส่วนของ source templates และ dictionaries
- แนบคู่มือติดตั้งใหม่ไว้ใต้ `avsf-installer/clean-package/` ซึ่งถูกป้องกันการเปิดผ่าน HTTP

## เพิ่ม Safe Site Reset (อันตราย/เลือกใช้เท่านั้น)
- จำกัดเฉพาะ Super Admin และ CSRF-protected POST
- ต้องติ๊กยืนยันขอบเขตและพิมพ์ `RESET AVSF SITE`
- สร้าง Safety Backup แบบ Files + Database ก่อนเริ่มทุกครั้ง
- ล้างเฉพาะ allowlist ของตาราง AVSF มาตรฐาน ไม่ DROP ตาราง และไม่ลบตารางที่ไม่รู้จัก
- คืน `settings` เป็นค่าเริ่มต้นชุดเดียวกับ Auto Installer และคงข้อความช่วยเหลือ/Placeholder/คำแปลใน source
- ล้าง uploads ได้เมื่อเลือก checkbox; หากไม่เลือก จะเก็บไฟล์สื่อไว้
- คง `config.php`, `config.local.php`, `.env`, `.htaccess`, Source และ protected backup location
- หลังล้างบัญชีผู้ใช้ทั้งหมด จะล้าง Admin Session และนำไปสร้าง Owner คนใหม่ผ่าน Setup Wizard โดยผูก install nonce เดิมกับ session เดิม

## ข้อจำกัด/การทดสอบ
- Clean export ต้องทดสอบบน Hosting จริงก่อนใช้ Production; ต้องมี PHP ZipArchive และพื้นที่เขียน private backup
- Reset เป็น destructive operation; Safety Backup ถูกบังคับ แต่การล้างไฟล์ระบบปฏิบัติการและ DB ไม่ใช่ distributed transaction หากลบไฟล์ uploads/cache/log/quarantine ไม่ครบ ระบบจะแจ้งเตือนหลัง DB commit และเก็บ Safety Backup ให้ Restore ได้
- ตารางนอก allowlist จะไม่ถูกล้างและจะแสดงในผลลัพธ์ เพื่อป้องกันการลบ extension data โดยไม่ทราบหน้าที่


## ตรวจเพิ่มก่อนแพ็ก Release
- เพิ่มค่า SEO เริ่มต้นที่ไม่ผูกกับเว็บไซต์ เช่น `index, follow`, ตัวคั่น Title, Twitter Card, แม่แบบ Meta Post และเพดาน Sitemap 20,000 URL ต่อชนิด โดยเว้นชื่อเว็บ/Keyword/Analytics/URL เฉพาะเว็บให้ผู้ดูแลกำหนดเอง
- แก้ Root rewrite ให้ `/db-setup.php` เปิดตัวเชื่อมต่อฐานข้อมูลครั้งแรก, `/setup.php` เปิด First-run Owner Wizard และ `/server-reconnect.php` แยกไว้สำหรับ Full Migration โดยตรง
- Clean exporter ตัด `.env.*`, `config.local.php.*`, SQL Dump, archive และไฟล์ credential เพิ่มเติม พร้อมปฏิเสธการสร้าง ZIP หากพบไฟล์เหล่านี้ในผลลัพธ์
- Site Reset ตรวจ Storage Engine ของตาราง AVSF ก่อนเริ่ม และปฏิเสธการล้างหากพบตารางที่ไม่ใช่ InnoDB เพื่อคงการ Rollback ของ Transaction
- เพิ่มคู่มือ Clean Site ทั้งภาษาไทยและอังกฤษภายใน ZIP ที่สร้างจาก Backup Center
- ตรวจไฟล์ Safety Backup ที่สร้างเสร็จด้วยการเปิด ZIP กลับมาตรวจ Manifest, SQL SHA-256, จำนวนตาราง/ไฟล์ และ CRC ก่อนเริ่ม Reset; หากตรวจไม่ผ่านจะไม่ลบข้อมูล
- เติมคำแปลอังกฤษของ Backup Center ครบทุกข้อความในหน้า 65/65 keys
- Clean exporter ตัดไฟล์ verification/robots/sitemap เฉพาะโดเมนเก่า รวมถึง Release Manifest ของแพ็กเกจเก่าออกจาก Clean ZIP
