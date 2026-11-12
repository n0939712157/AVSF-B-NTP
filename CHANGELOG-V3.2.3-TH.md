# AVSF B-NTP v3.2.3 — Site Center Navigation & Core Dependency Repair

## แก้ไขสำคัญ
- กู้คืน `avsf-core/cache/cache_lib.php` ซึ่งหายจากแพ็กเกจ v3.2.1–v3.2.2 แต่ยังถูกเรียกโดย Admin, Frontend, Cache, File Manager และ Data Transfer
- ปรับการจัดการคลิกเมนู Site Center เป็น delegated handler เดียว ครอบคลุม Sidebar, Dashboard Cards และการคลิกที่ SVG/ไอคอนด้านใน
- ตรวจสอบ Origin และเส้นทาง `/admin-login/` ก่อนเปิด iframe เพื่อป้องกันการนำทางออกนอกเว็บไซต์
- เพิ่มสถานะกำลังโหลดและข้อความสำรอง พร้อมลิงก์เปิดหน้าโดยตรงเมื่อหน้าไม่ตอบสนอง/ใช้เวลาโหลดเกินกำหนด
- ตรวจจับการ redirect กลับหน้า Login และข้อความ PHP/HTTP Error ที่อ่านได้ภายใน same-origin iframe
- คงลิงก์ดาวน์โหลด ลิงก์ภายนอก และลิงก์กลับหลังบ้านให้ทำงานตามพฤติกรรมเดิม

## หมายเหตุ
แพ็กเกจนี้เป็น In-Place Update ไม่รวม `config.local.php`, `.env`, SQL dump หรือข้อมูล uploads ของเว็บไซต์เดิม ไม่ต้อง Import SQL สำหรับการอัปเดตนี้
