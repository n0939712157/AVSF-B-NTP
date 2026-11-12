# คู่มือ Clean Site Package และ Safe Reset — AVSF v3.2.0

## 1) สร้าง ZIP เว็บใหม่แบบสะอาด (แนะนำ)
เปิดหลังบ้าน → Backup & Restore → สร้าง Clean Site ZIP

การส่งออกนี้ **ไม่ลบและไม่แก้ไขเว็บที่กำลังใช้งาน** สร้างสำเนา Source ที่ไม่มีข้อมูลเว็บไซต์เดิม เหมาะสำหรับนำไปติดตั้งเป็นเว็บใหม่

### เก็บไว้
- ซอร์ส AVSF, Admin, Site Center, Frontend, Installer, CSS/JS และภาษาไทย/อังกฤษ พร้อมคู่มือติดตั้งทั้ง TH/EN
- `config.php` ทั่วไป, `.htaccess`, `database-schema.sql`
- ค่าตั้งต้นจาก `avsf_default_settings()` ซึ่งเป็นชุดเดียวกับ Auto Installer รวมคำแนะนำ SEO พื้นฐาน เช่น Robots `index, follow`, ตัวคั่น Title, Twitter Card, แม่แบบ Meta Post และขีดจำกัด Sitemap; ส่วนชื่อแบรนด์ โดเมน Keyword Analytics และข้อมูลเฉพาะเว็บจะเว้นให้กรอกเอง
- ชื่อหัวข้อ คำอธิบาย คำแนะนำ ตัวอย่าง Placeholder และแนวทาง SEO ที่อยู่ใน source templates

### ไม่รวม
- `config.local.php`, `.env`, credential ของฐานข้อมูล
- SQL dump และข้อมูลผู้ใช้/Role/Password Hash/2FA
- โพสต์, หน้า, ค่าย, นักแสดง, taxonomy, banner, comment, redirects, SEO overrides และ logs
- ไฟล์ media ใน `uploads`, cache, runtime logs, quarantine และ backup history
- API key, token, SMTP credentials, proxy และ secrets

### ติดตั้งบนเว็บใหม่
1. สร้างฐานข้อมูล MySQL และ User ใหม่ที่ Hosting
2. แตก ZIP ทั้งชุดลง Document Root
3. เปิด `/db-setup.php` (หน้าเชื่อมต่อฐานข้อมูลครั้งแรก) แล้วกรอก MySQL Host, Port, Database Name, Username และ Password
4. Auto Installer สร้างตารางและค่าเริ่มต้น โดยไม่ต้องนำ SQL dump จากเว็บเก่ามา Import
5. เปิด `/setup.php` เพื่อสร้าง Owner คนแรกและตั้งค่า 2FA
6. เข้าหลังบ้าน ตั้งชื่อเว็บ, URL, SEO, Permalink, Mail และการเชื่อมต่อภายนอกที่ต้องการใช้

## 2) ล้างข้อมูลเว็บไซต์ปัจจุบัน (เสี่ยงสูง)
ใช้เฉพาะเมื่อจะเริ่มเว็บเดิมใหม่จริง ๆ
- จำกัด Super Admin
- ตรวจสอบให้ทุกตาราง AVSF ที่จะล้างใช้ InnoDB ก่อน เพื่อให้ Transaction/Rollback ทำงานได้
- สร้าง Full Safety Backup (ไฟล์ + ฐานข้อมูล) ในพื้นที่ส่วนตัวนอก Document Root ก่อน และเปิด ZIP ตรวจ CRC/Manifest/SHA-256 ของ SQL/จำนวนตาราง/จำนวนไฟล์อีกครั้งก่อนเริ่มลบ หากตรวจไม่ผ่านจะหยุดโดยไม่แตะข้อมูล
- ล้างเฉพาะตาราง AVSF ที่กำหนดไว้ใน allowlist; ไม่ DROP tables และไม่แตะตาราง extension/unknown
- คืนค่า Settings จาก `avsf_default_settings()` โดยคง labels/help/placeholder ในซอร์ส
- ถ้าเลือก ลบ media จะล้างไฟล์ uploads หลัง Safety Backup; ถ้าไม่เลือก ไฟล์สื่อเดิมจะยังอยู่แต่โพสต์ถูกล้างแล้ว
- บัญชีทุกบัญชีจะถูกลบ ต้องสร้าง Owner คนใหม่จาก Setup Wizard
- `config.local.php`, `.env`, `config.php`, `.htaccess` และ Source ไม่ถูกลบ

## 3) คำแนะนำความปลอดภัย
- แนะนำใช้ Clean Site ZIP Export สำหรับทำเว็บใหม่ แทนการล้างเว็บ Production ที่กำลังใช้งาน
- ดาวน์โหลด Safety Backup ไปเก็บไว้อีกที่ก่อน Reset
- ตารางนอก allowlist จะไม่ถูกล้าง ต้องตรวจด้วยตนเอง
- หากการล้างไฟล์สื่อผิดพลาดหลังฐานข้อมูล commit ให้ใช้ Safety Backup Restore
