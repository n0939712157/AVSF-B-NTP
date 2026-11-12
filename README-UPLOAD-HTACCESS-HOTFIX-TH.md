# AVSF v3.2.4 — Image Upload / .htaccess Hotfix (TH)

## สาเหตุที่แก้
จากภาพ การเปิด `https://โดเมน/uploads/AVSUBFIN.webp` ส่งหน้า 500 ที่แจ้งปัญหา `.htaccess` จึงชี้ไปที่กฎในโฟลเดอร์ storage/uploads มากกว่าตัวไฟล์ภาพโดยตรง

แพตช์ก่อนหน้าปรับ PHP ให้อัปโหลดโดยคงชื่อไฟล์ต้นฉบับ แต่ไม่ได้แก้ `.htaccess` ภายใน `avsf-storage` และ `avsf-storage/uploads` ซึ่งมี `Options -ExecCGI` และ `RemoveHandler/RemoveType` ที่ Hosting บางประเภทไม่อนุญาต

## ไฟล์ที่รวม
- `avsf-core/functions.php` — คงชื่อไฟล์ต้นฉบับเมื่ออัปโหลด
- `avsf-core/security/security_lib.php` — กฎตรวจสอบอัปโหลดเดิม
- `avsf-core/lang/en/core-frontend.php` — ข้อความแปลประกอบ
- `avsf-admin/index.php` — Media Library รองรับชื่อไฟล์เดิม
- `.htaccess` — บล็อกไฟล์สคริปต์ผ่าน `/uploads/` ก่อน map เข้าสู่ storage
- `avsf-storage/.htaccess` และ `avsf-storage/uploads/.htaccess` — ลดเหลือ comment ไม่มี directive ที่มักถูก Hosting ปฏิเสธ

## วิธีติดตั้ง
1. สำรองไฟล์เดิมอย่างน้อย `.htaccess`, `avsf-storage/.htaccess`, `avsf-storage/uploads/.htaccess` และ PHP 4 ไฟล์ในแพ็กเกจ
2. แตก ZIP ลง Document Root โดยคงโครงสร้างโฟลเดอร์และเขียนทับไฟล์เดิม
3. ตรวจว่าไฟล์ภาพอยู่จริงใน `avsf-storage/uploads/` และโฟลเดอร์นี้เขียนได้โดย PHP user ของ Hosting
4. ล้าง Cache เบราว์เซอร์/Cache ของ Hosting แล้วเปิด URL ภาพเดิม เช่น `/uploads/AVSUBFIN.webp`
5. เมื่อ URL ภาพแสดงได้ ให้กลับไป Media Library แล้วรีเฟรชหน้า

## ความปลอดภัย
- กฎ root ปฏิเสธนามสกุลที่เป็นสคริปต์ผ่าน URL `/uploads/` (PHP/PHTML/PHAR/CGI/PL/PY/SH/ASP/ASPX/JSP)
- กฎ root เดิมยังปฏิเสธ direct request ไป `avsf-storage/`
- ไม่ลบหรือเปลี่ยนชื่อภาพเก่า และไม่แก้ฐานข้อมูล
- ไม่รวม config, credentials หรือ SQL

## ข้อจำกัด
แพตช์นี้แก้สาเหตุ `.htaccess` ที่เห็นจากภาพ แต่ไม่สามารถพิสูจน์ว่าไฟล์ `AVSUBFIN.webp` ยังอยู่บนเซิร์ฟเวอร์หรือมี Permission ถูกต้องได้จากระยะไกล หากแก้แล้วยัง 404 ให้ตรวจไฟล์จริงใน `avsf-storage/uploads/`; หากยัง 500 ให้ตรวจ Error Log ของ Hosting เพื่อระบุ directive ที่เซิร์ฟเวอร์ปฏิเสธ
