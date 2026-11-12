AVSF B-NTP — CLEAN SITE PACKAGE
=================================

วัตถุประสงค์
แพ็กเกจนี้เป็นซอร์ส AVSF สำหรับติดตั้งเว็บไซต์ใหม่แบบสะอาด โดยไม่แก้ไขเว็บไซต์ต้นทางที่ใช้สร้าง ZIP

สิ่งที่รวมอยู่
- ซอร์ส AVSF ทั้งหมด รวม Admin, Site Center, Frontend, Installer, CSS, JavaScript และไฟล์ช่วยเหลือ
- config.php แบบทั่วไปและ .htaccess สำหรับ route/security ของ AVSF
- database-schema.sql และค่าเริ่มต้นที่สร้างจาก auto-install.php รุ่นเดียวกัน
- ข้อความช่วยเหลือ ป้ายชื่อ คำแนะนำ และ Placeholder ในหน้า Settings ยังคงอยู่ เพราะเป็นส่วนหนึ่งของซอร์สและไฟล์ภาษา

สิ่งที่ไม่รวม
- config.local.php, .env, รหัสผ่านฐานข้อมูล และข้อมูลเชื่อมต่อเฉพาะเว็บ
- SQL dump ของเว็บไซต์ต้นทาง, ผู้ใช้, รหัสผ่าน Hash, 2FA Secret และข้อมูล Activity
- โพสต์ หน้าเพจ นักแสดง ค่าย หมวดหมู่ แท็ก Redirect/SEO Override และเนื้อหาเว็บไซต์เดิม
- ไฟล์สื่อใน uploads, cache, logs, backups, quarantine และไฟล์ runtime
- API Key, Token, Proxy, SMTP Credential และ secret ที่ผู้ดูแลกรอกในเว็บต้นทาง

วิธีติดตั้งบน Hosting ใหม่
1. แตก ZIP ทั้งชุดลงใน Document Root ของโดเมนใหม่ (ให้ไฟล์ index.php และ config.php อยู่ที่ Root)
2. สร้าง MySQL Database และ User ใหม่ในแผงควบคุม Hosting พร้อมกำหนดสิทธิ์ใช้งาน
3. เปิด https://โดเมนใหม่/db-setup.php แล้วกรอก MySQL Host, Port, Database Name, Username และ Password
4. ระบบสร้าง config.local.php และเชื่อมต่อฐานข้อมูล จากนั้นสร้างตาราง/ค่าเริ่มต้นอัตโนมัติ
5. เปิด /setup.php เพื่อสร้างบัญชี Owner คนแรก ตั้งรหัสผ่าน และเปิดใช้งาน 2FA
6. เข้าสู่หลังบ้าน ตรวจ Site URL, SEO, Permalink, Upload Permission และการส่งอีเมลก่อนเปิดเว็บจริง

สำคัญ
- อย่า Import SQL dump จากเว็บต้นทางลงฐานข้อมูลใหม่ เพราะแพ็กเกจนี้ตั้งใจเริ่มด้วยฐานข้อมูลใหม่
- อย่านำ config.local.php หรือ .env จากเว็บเก่ามาใช้กับโดเมนใหม่ เว้นแต่ตั้งใจใช้ฐานข้อมูลเดียวกันและตรวจสอบแล้ว
- หลังสร้าง Owner แล้ว ให้ปิด/จำกัดการเข้าถึงไฟล์ติดตั้งตามนโยบาย Hosting และเก็บ ZIP ต้นฉบับไว้ในพื้นที่ส่วนตัว
- การสร้าง Clean Package ไม่ลบข้อมูลใด ๆ จากเว็บไซต์ต้นทาง
