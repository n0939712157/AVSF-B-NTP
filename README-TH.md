# AVSF-B-NTP

นี่คือชุดไฟล์ AVSF แบบเต็มที่รวบรวมรายงาน ฟีเจอร์ การปรับปรุงระบบ และประวัติแพตช์จากช่วงเริ่มต้นจนรุ่นล่าสุด เพื่อให้สามารถอัปโหลดสู่ GitHub และจัดเก็บเป็นแหล่งอ้างอิงเดียวได้

## ภาพรวมโครงการ

- ชื่อโปรดักต์: AVSF
- เจ้าของ/ผู้สร้าง: B-NTP
- เวอร์ชัน: 3.2.4 พร้อม refinements ล่าสุดถึง 3.2.8
- ประเภทแพกเกจ: Full source + update bundle + release documentation
- ใช้สำหรับ: ติดตั้งบนเว็บไซต์ AVSF ที่มีอยู่ หรือใช้เป็นสืบค้นและอ้างอิงของระบบแบบเต็ม

## ส่วนประกอบระบบที่รวมอยู่

- `avsf-core/` — ระบบหลัก ความปลอดภัย การสำรองข้อมูล การแปลภาษา การนำเข้า และการจัดการอัปเดต
- `avsf-frontend/` — เทมเพลตหน้าเว็บ การแสดงโพสต์ การจัดกลุ่มแท็ก/หมวดหมู่ และ UI ที่ตอบสนอง
- `avsf-admin/` — เมนูจัดการหลังบ้านและโมดูลการดูแลระบบ
- `avsf-installer/` — เครื่องมือติดตั้งและการสร้าง Schema ฐานข้อมูล
- `avsf-storage/` — โฟลเดอร์เก็บไฟล์อัปโหลดและการตั้งค่าความปลอดภัย
- ไฟล์ PHP หลัก — หน้าเว็บ ระบบสมัคร เข้าสู่ระบบ หน้าโพสต์ RSS Sitemap Robots และฟังก์ชันสำคัญอื่น ๆ
- `config.php` และ `config.local.example.php` — การตั้งค่าเบื้องต้นของเว็บไซต์

## จุดเด่นของระบบที่ผนวกอยู่

- การทำงานแบบ responsive สำหรับ taxonomy และหน้า content
- การจัดการ SEO และ metadata ตามชนิดของเนื้อหา
- ปุ่มและแผงควบคุมแบบ admin dock ที่มีการตรวจสิทธิ์
- การจัดการ URL inventory และความเรียบร้อยของหน้าเว็บ
- หน้า category / studio / actor / tag / card ที่มีการออกแบบหลากหลายและสวยงาม
- การรองรับหลายภาษาและการแปลภาษา (i18n)
- การจัดการไฟล์อัปโหลดและการป้องกันการเรียกไฟล์สคริปต์โดยตรง
- โมดูลสำรองข้อมูล ความปลอดภัย และการนำเข้าข้อมูล

## ประวัติรุ่นและแพตช์ที่บรรจุ

มีไฟล์บันทึกการเปลี่ยนแปลงและ patch หลายฉบับ รวมถึง:

- `CHANGELOG-V3-TH.md`
- `CHANGELOG-V3.1-TH.md`
- `CHANGELOG-V3.1.2-TH.md`
- `CHANGELOG-V3.2.0-TH.md`
- `CHANGELOG-V3.2.1-TH.md`
- `CHANGELOG-V3.2.2-TH.md`
- `CHANGELOG-V3.2.3-TH.md`
- `CHANGELOG-V3.2.4-TH.md`
- `CHANGELOG-COMPACT-UNIFORM-CARD-PANELS-TH.md`
- `CHANGELOG-COVER-CATEGORY-RESTORE-TH.md`
- `CHANGELOG-CYBER-NEON-TAXONOMY-TH.md`
- `CHANGELOG-DETAIL-INFO-ICONS-COMPACT-ROWS-TH.md`
- `CHANGELOG-GALLERY-HOVER-TAP-PREVIEW-TH.md`
- `CHANGELOG-INSTANT-TOUCH-SELECTION-TH.md`
- `CHANGELOG-MEDIA-FALLBACK-TH.md`
- `CHANGELOG-NO-COVER-ART-ZOOM-TH.md`
- `CHANGELOG-ORIENTATION-AWARE-POSTER-AND-COMPACT-DETAIL-TH.md`
- `CHANGELOG-ORIGINAL-CARD-SIZE-POSTER-REFINE-TH.md`
- `CHANGELOG-PORTRAIT-FILL-LONGPRESS-TH.md`
- `CHANGELOG-POST-FALLBACK-CATEGORY-ART-TH.md`
- `CHANGELOG-POSTER-NATURAL-FIT-BORDERLESS-TH.md`
- `CHANGELOG-RECOMMENDED-NO-COVER-TH.md`
- `CHANGELOG-REFERENCE-ARTWORK-TH.md`
- `CHANGELOG-RESPONSIVE-ALL-DEVICES-TH.md`
- `CHANGELOG-RESPONSIVE-ART-NATURAL-COVERS-TH.md`
- `CHANGELOG-RESPONSIVE-DETAIL-ROW-SPACING-TH.md`
- `CHANGELOG-SELECTION-OUTLINE-INSTANT-PREVIEW-TH.md`
- `CHANGELOG-STANDARD-UNIFORM-POST-CARDS-TH.md`
- `CHANGELOG-UNIFORM-COVER-FRAMES-TH.md`

## รายงานตรวจสอบและประเมินผล

มีเอกสารรายงานการวิเคราะห์และทดสอบหลายฉบับ เช่น:

- `AUDIT-REPORT-TH.md`
- `shard-A-report.md`
- `shard-B-report.md`
- `shard-C-report.md`
- `shard-D-report.md`
- `shard-E-i18n-report.md`
- `README-TEST-PRIVATE-TH.md`
- `README-FULL-SOURCE-TH.md`
- `README-UPLOAD-FIX-TH.md`
- `README-UPLOAD-HTACCESS-HOTFIX-TH.md`
- `CLEAN-SITE-FEATURE-TH.md`
- `I18N-CONVERSION-SPEC.md`
- `DEVELOPER.md`
- `LIGHT-DARK-THEME-FIX-TH.txt`

## ใบสัญญาและ metadata

- `AVSF-METADATA.json`
- `AVSF-FULL-TEST-PRIVATE-MANIFEST.json`
- `AVSF-V3.2.1-UPDATE-MANIFEST.json`
- `AVSF-V3.2.4-FULL-SOURCE-MANIFEST.json`
- `UPDATE-MANIFEST.json`
- `PATCH-MANIFEST.json`
- `SHA256SUMS`

## วิธีติดตั้งและอัปโหลด

1. สำรองเว็บไซต์และฐานข้อมูลที่ใช้อยู่ก่อน
2. ทดสอบบน staging site ก่อนใช้งานจริง
3. อัปโหลดไฟล์ทั้งหมดลงในโฟลเดอร์ Document Root ของ AVSF และเขียนทับไฟล์ที่ตรงกันแทน
4. เก็บ `config.local.php`, ฐานข้อมูลเดิม และไฟล์อัปโหลดที่อยู่ใน `avsf-storage/uploads/` ไว้
5. ล้างแคชของ AVSF และแคชจากฮอสติ้ง/CDN หลังติดตั้ง
6. ตรวจสอบหน้าเว็บส่วนหน้าแรก หน้าโพสต์ หน้าแอดมิน การอัปโหลด Sitemap และ SEO

## ข้อควรระวัง

- เป็นแพตช์ overlay ไม่ใช่การติดตั้ง fresh site
- ไม่รวม credentials จริงหรือข้อมูลผู้ใช้งานจริง
- ไม่ควรลบโฟลเดอร์ uploads หรือ config เดิมโดยไม่ได้วางแผนอย่างชัดเจน
- ควรทดสอบก่อนนำไปใช้บน production

## สถานะการจัดเก็บบน GitHub

ชุดไฟล์นี้ถูกจัดเตรียมไว้สำหรับอัปโหลดไปยัง GitHub และรวมเอกสารรายงาน สารบบ คำอธิบายฟีเจอร์ และบันทึกรุ่นจากช่วงเริ่มต้นจนถึงโฉมล่าสุดไว้ใน repository เดียว
