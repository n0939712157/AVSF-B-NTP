# AVSF B-NTP v3.2.1 — Desktop Floating Controls

วันที่: 9 พฤศจิกายน 2026

## ปรับปรุง Floating Controls
- Desktop: เพิ่มแถบลอยด้านขวาสำหรับเปลี่ยน Light/Dark และภาษาไทย/อังกฤษ ทั้ง Admin และ Site Center
- Desktop: ไม่แสดงปุ่มเมนูในแถบลอย เพราะ Sidebar อยู่ทางซ้ายและมองเห็นอยู่แล้ว
- Mobile/Tablet: คงแถบลอย 3 ปุ่ม ได้แก่ เมนู, ธีม และภาษา
- ลดปุ่มธีม/ภาษาในตำแหน่งเดิมที่ซ้ำกับแถบลอย โดยคงตัวเลือก Accent Color และการตั้งค่าสีเดิม
- จำกัดความสูงของแถบลอยไม่ให้เกิน viewport และเพิ่ม safe-area spacing
- เพิ่มพื้นที่ด้านขวาของเนื้อหาหลักเพื่อไม่ให้แถบลอยบังข้อมูล
- อัปเดต cache-busting URL ของ admin-ui.css และ file-manager-center.css เป็น v3.2.1

## ความเข้ากันได้
- ไม่แก้ Schema หรือข้อมูลฐานข้อมูล
- ไม่รวม config.local.php, .env, uploads หรือข้อมูลสำรอง
- ใช้ localStorage key เดิมของธีมและตัวโหลดภาษาเดิม

## QA
- PHP Syntax: ตรวจผ่านไฟล์ Admin และ Site Center ที่แก้ไข
- ZIP Integrity: ตรวจ CRC และ Path ซ้ำ
- Manifest: คำนวณ SHA-256 ใหม่ทุกไฟล์ในแพ็กเกจ
- ยังต้องทดสอบ visual/interaction บน Browser และ Hosting จริงก่อน Production
