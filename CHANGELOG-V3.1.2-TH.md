# AVSF B-NTP v3.1.2 — Changelog

วันที่สร้าง: 7 พฤศจิกายน 2026
ประเภท: UI/UX and File Manager usability update (Full Replace Clean)

## 1. Floating mobile controls
- เพิ่มแถบปุ่มลอยด้านข้างสำหรับเปิดเมนู, สลับโหมดสว่าง/มืด และเปลี่ยนภาษา ไทย/อังกฤษ
- ใช้ปุ่มเมนูเดิมเป็นตัวทำงานหลักผ่านการ dispatch click เพื่อคง logic เปิด/ปิด sidebar เดิม ไม่สร้างระบบเมนูซ้ำ
- ใช้ `avsf-admin-theme` key เดิมเพื่อให้ธีมสอดคล้องกับหน้าหลังบ้านและ iframe
- ซ่อนปุ่มซ้ำใน header เฉพาะ breakpoint มือถือ และเว้นขอบขวาให้เนื้อหาไม่อยู่ใต้ dock
- ซ่อน Admin dock ภายใน iframe เพื่อป้องกันการซ้อนกับ Site Center dock ของหน้าหลัก

## 2. File Manager source editor
- เพิ่มปุ่ม SVG: ค้นหา, ย่อหน้าเข้า/ออก, คอมเมนต์, จับคู่วงเล็บ, ไปยังบรรทัด, เลือกทั้งหมด, คัดลอกข้อความที่เลือก, ดาวน์โหลดไฟล์ปัจจุบัน และตัดบรรทัด
- คอมเมนต์ใช้ syntax ตาม extension ที่รองรับ: PHP/JS, SQL, INI/YAML/CONF, CSS, HTML/XML/SVG/Markdown
- ปฏิเสธการเติม comment ใน JSON เพราะ JSON ไม่รองรับ comment ตามมาตรฐาน
- เพิ่ม keyboard shortcuts: Ctrl/Cmd+S, Ctrl/Cmd+F, Ctrl+/ และ Tab/Shift+Tab
- ปุ่มทั้งหมดทำงานบน textarea เดิม ไม่ต้องโหลด code editor library ภายนอก

## 3. File Manager mobile layout
- แสดง action buttons ใต้ไฟล์/โฟลเดอร์เป็น grid 2 คอลัมน์ใน mobile card และปรับขนาดสำหรับแท็บเล็ตถึง 900px
- จำกัด `min-width`, `max-width`, `overflow-wrap` และปุ่มให้พอดีกับ card
- ปรับทั้ง standalone File Manager และ iframe stylesheet ของ Site Center

## 4. i18n and cache busting
- เพิ่ม English dictionary entries สำหรับเครื่องมือใหม่และ mobile dock
- เปลี่ยน query version ของ `file-manager-center.css` และ `admin-ui.css` เป็น 3.1.2

## 5. Data preservation
- คง `config.local.php` และ `.env` เดิมบนเซิร์ฟเวอร์ ไม่บรรจุใน ZIP
- ไม่รวม SQL dump และ media uploads
- ไม่มีขั้นตอน Import/Drop database ในการอัปเดตนี้

## 6. QA scope
- ตรวจ PHP syntax ทุกไฟล์ PHP ในแพ็กเกจ
- ตรวจ JavaScript inline ที่ปรับปรุงด้วย `node --check` หลังแทน PHP expressions ด้วยค่าทดสอบ
- ตรวจ CSS parse ของ stylesheet ที่แก้ไข
- ตรวจ ZIP integrity, path duplicates, manifest SHA-256 และการไม่มีไฟล์ config.local.php/.env/media
- ยังไม่ได้ทำ live Hosting / MySQL / real-device end-to-end test


## ปรับปรุงเพิ่มเติมจากภาพทดสอบมือถือ

- เพิ่มปุ่มเลือกทั้งหมด, คัดลอกข้อความที่เลือก และดาวน์โหลดไฟล์ปัจจุบันใน Code Editor
- เพิ่มสถานะแสดงจำนวนบรรทัดและตำแหน่ง Ln/Col พร้อมจำนวนตัวอักษรและไบต์
- เพิ่มคำแปล EN สำหรับปุ่มและข้อความแจ้งเตือนของเครื่องมือแก้ไขชุดใหม่
- ปรับการยกเลิก Object URL ของไฟล์ดาวน์โหลดให้หน่วงเวลาเล็กน้อยเพื่อความเข้ากันได้กับ Safari
- ควบคุมความกว้างของปุ่มจัดการใน File Manager บนมือถือ และให้ชื่อไฟล์ยาวตัดบรรทัดได้
- เพิ่ม fallback การส่งฟอร์ม Save สำหรับ Safari/Browser รุ่นที่ไม่มี requestSubmit()
- ปรับแถวปุ่มจัดการและซ่อนคอลัมน์ที่ไม่จำเป็นบนแท็บเล็ต (สูงสุด 900px) เพื่อลดการเบียดพื้นที่แนวนอน
- แก้ Event bubbling ของปุ่มเมนูลอยใน Site Center เพื่อไม่ให้เมนูเปิดแล้วปิดทันทีจากตัวจับ outside-click
