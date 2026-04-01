Project responsive-message-app adalah project fast respon pengiriman pesan ke pihak SMKN 12 Jakarta berbasis PHP, MySQL, Javascript, CSS.
User yang diperkenankan mengirim pesan melalui apps ini adalah user yang sudah terdaftar dalam database (bisa mengirim pesan melalui jalur login atau melalui
Kirim Pesan Tanpa Login) dan user yang belum pernah terdaftar di database bisa mengirim pesan melalui fitur Kirim Pesan Tanpa Login

Semua pengiriman pesan akan diberikan Reference Number yang akan sangat berguna bagi user pengirim pesan untuk tracing progress pesannya
sudah direspon sejauh apa melalui fitur Lacak Status Pesan.

Pada halaman Utama (index.php) di sertakan QRCode untuk fast access login bagi user untuk menuju apps tanpa ketik manual address apps ini.

Fitur Reference Number dikirim via Email Mailersend dan Whatsapp Fonnte (yang pada saat ini tools tersebut masih berstatus berbayar)

Apps ini dibagi menjadi x bagian pasca login :
1. Dashboard Admin diakses dan dimanage oleh login username admin
2. Dashboard Follow-Up Pesan diakses diakses dan dimanage oleh login username Guru_BK atau Guru Khusus lainnya yang berstatus Guru Responder
3. Formulir Kirim Pesan diberikan akses kepada Guru umum (bukan guru responder), orang tua dan siswa atau user lain yang tidak bersifat responder
   Bagi user selain guru responder yang mengirim pesan setelah login dapat mentracing progress respons pesannya lebih lengkap dibanding
   hanya menggunakan fitur Lacak Status Pesan
4. Dasboard Wakil Kepala Sekolah dan Dashboard Kepala Sekolah adalah responder lanjutan
5. Semua user yang memiliki akses Dashboard masing-masing diberikan fitur lengkap berupa grafik-grafik dan tabel-tabel untuk merespon dan memantau pesan
6. yang masuk ke apps dan dapat create report juga.

Apps ini juga sedang dikembangkan untuk disinkronisasi ke dalam bentuk Flutter...ON PROGRESS
