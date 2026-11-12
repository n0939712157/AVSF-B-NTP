# AVSF B-NTP v3.2.2 — Site Center Full-Viewport Layout

วันที่: 9 พฤศจิกายน 2026

## ปัญหาที่แก้ไข
- แก้พื้นที่ว่างด้านขวาของหน้า Site Center ที่เกิดจากการเว้น `padding-right` 72px บน Desktop และ 58px บน Mobile เพื่อหลบ Floating Rail
- ปรับพื้นที่หลักของ Site Center ให้กว้างเต็มพื้นที่ที่เหลือหลัง Sidebar เหมือนหน้า Admin ปกติ
- ให้แถบลอยธีม/ภาษาแสดงซ้อนอยู่เหนือเนื้อหา (overlay) แทนการบีบความกว้างของหน้า
- คงระยะ padding ภายใน Container และการ์ด เพื่อไม่ให้เนื้อหาชิดขอบจอ
- กำหนด `min-width:0`, `max-width:100%` และ `overflow-x:clip` ให้ main/panel ลดปัญหา horizontal overflow
- ปรับ cache-busting ของ `admin-ui.css` และ `file-manager-center.css` เป็น v3.2.2

## สิ่งที่คงเดิม
- Sidebar Desktop และเมนูลอย Mobile
- Floating Rail สำหรับธีมและภาษา
- ระบบภาษาไทย/อังกฤษ, Light/Dark, Clean Site Export และ Backup & Restore
- ไม่แก้ schema, ไม่ import SQL และไม่แก้การเชื่อมต่อฐานข้อมูล
- ไม่รวม `config.local.php`, `.env`, uploads หรือข้อมูล runtime ของเว็บไซต์

## วิธีอัปเดต
1. สำรองไฟล์และฐานข้อมูลก่อน
2. แตก ZIP แบบ Merge/Overwrite ไปยัง Document Root
3. ใช้ `config.local.php` เดิม และเก็บ `.env` เดิมหากใช้งาน
4. ไม่ต้อง Import SQL
5. ล้าง Browser/Server cache หาก CSS เก่ายังค้าง

## QA
- ตรวจ PHP Syntax ทุกไฟล์ในแพ็กเกจ
- ตรวจ JavaScript ที่ฝังใน PHP ด้วย Node.js หลังแยกบล็อก inline script
- ตรวจ CSS syntax ด้วย parser
- ตรวจ ZIP CRC, path ซ้ำ และ SHA-256 Manifest
- ยังต้องยืนยันผลภาพบน Hosting/Browser จริงก่อน Production
