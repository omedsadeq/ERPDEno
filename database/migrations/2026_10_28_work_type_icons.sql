-- «جۆری ئیش» as cards: every work type carries an icon (assets/js/treatment-icons.js,
-- the keys in config.php WORK_TYPE_ICONS). The types there are now get theirs here,
-- by work_key; one added later picks its own, and one without is guessed from its name.
ALTER TABLE work_types ADD COLUMN IF NOT EXISTS icon VARCHAR(30) NULL AFTER work_name;

UPDATE work_types SET icon = CASE work_key
    WHEN 'cleaning' THEN 'cleaning'   -- پاککردنەوەی ددان
    WHEN 'filling' THEN 'filling'   -- پڕکردنەوە
    WHEN 'extraction' THEN 'extraction'   -- دەرهێنان
    WHEN 'root_canal' THEN 'root_canal'   -- گەڕی ڕەگ
    WHEN 'crown' THEN 'crown'   -- تاج
    WHEN 'bridge' THEN 'bridge'   -- پرد
    WHEN 'braces' THEN 'orthodontic'   -- پەلکەزێرینە
    WHEN 'implant' THEN 'implant'   -- چاندن
    WHEN 'whitening' THEN 'whitening'   -- سپیکردنەوە
    WHEN 'checkup' THEN 'exam'   -- پشکنینی گشتی
    WHEN 'custom_1778502794_510' THEN 'orthodontic'   -- راستكردنه‌وه‌ی ددان
    WHEN 'custom_1778502806_310' THEN 'impression'   -- قالب گرتن
    WHEN 'custom_1778927378_473' THEN 'cementation'   -- دانانه‌وه‌
    WHEN 'custom_1778932618_480' THEN 'cementation'   -- چه‌سپی ددانی به‌ردایه‌
    WHEN 'custom_1778934415_844' THEN 'surgery'   -- داو ده‌رهینان
    WHEN 'custom_1779030206_332' THEN 'consult'   -- پسیاركردن
    WHEN 'custom_1779278460_115' THEN 'extraction'   -- كێشان
    WHEN 'custom_1779303139_853' THEN 'root_canal'   -- ده‌مار برین
    WHEN 'custom_1779543642_866' THEN 'root_canal'   -- پركردنه‌وه‌ ده‌ماربرین
    WHEN 'custom_1779544591_258' THEN 'pain'   -- ئازاری هه‌یه‌
    WHEN 'custom_1780222568_141' THEN 'broken'   -- ددانی شكایه‌
    WHEN 'custom_1780223186_725' THEN 'pain'   -- كێشه‌ی شه‌ویلگه‌
    WHEN 'custom_1780235916_713' THEN 'denture'   -- تاخم
    WHEN 'custom_1783345705_191' THEN 'crown'   -- کیف
    WHEN 'custom_1783423470_647' THEN 'cleaning'   -- شوشتنه‌وه‌
    WHEN 'custom_1783496923_889' THEN 'followup'   -- پێشتر ئیشی لۆ كرایه‌ به‌س ددانه‌كه‌ی به‌دل نیه‌
    WHEN 'custom_1783579223_660' THEN 'temporary'   -- دانانی ددانی موه‌قه‌ت
    WHEN 'custom_1783598499_305' THEN 'veneer'   -- جوانكاری
    WHEN 'custom_1783600656_552' THEN 'crown'   -- دانانەوەی تێستی ددان
    WHEN 'custom_1783773280_717' THEN 'surgery'   -- داوده‌رینه‌ر
    WHEN 'custom_1783845250_113' THEN 'loose'   -- برغی شلبووە
    WHEN 'custom_1783850541_681' THEN 'orthodontic'   -- تێل دانان
    WHEN 'custom_1783853735_323' THEN 'broken'   -- ددانی شكایه‌
    WHEN 'custom_1783857831_674' THEN 'wisdom'   -- ددانی عه‌قلی
    WHEN 'custom_1783867049_265' THEN 'pain'   -- کێشەی هەیە
    WHEN 'custom_1783878554_120' THEN 'pain'   -- كیشه‌ی هه‌یه‌
    WHEN 'custom_1783944290_143' THEN 'pain'   -- ددانی ده‌گری
    WHEN 'custom_1783955156_344' THEN 'swelling'   -- كیسه‌ی  دروست كردوه‌
    WHEN 'custom_1783955240_974' THEN 'loose'   -- ددانی شل بوه‌
    WHEN 'custom_1783956404_840' THEN 'broken'   -- ددانی موه‌قه‌تی شكایه‌
    WHEN 'custom_1784058143_136' THEN 'extraction'   -- ددانی كێشایە بەس مەوادی تەعقیمی بۆ دادەنرێ
    WHEN 'custom_1784103026_701' THEN 'broken'   -- ددنی زمان ئه‌گری
    WHEN 'custom_1784111419_606' THEN 'temporary'   -- ددانی موه‌قه‌ت
    WHEN 'custom_1784119357_183' THEN 'swelling'   -- پدوی کێشەی هەیە
    WHEN 'custom_1784136163_174' THEN 'filling'   -- حەشوەی دەرهاتیە
    WHEN 'custom_1784137646_542' THEN 'implant'   -- چاندن کراوە بۆ مراجعە هاتیە
    WHEN 'custom_1784206516_117' THEN 'orthodontic'   -- تێل لادان
    WHEN 'custom_1784223669_355' THEN 'swelling'   -- هەوکردنی پدو
    WHEN 'custom_1784396263_578' THEN 'pain'   -- حاله‌ت طه‌واری
    WHEN 'custom_1784398698_101' THEN 'veneer'   -- جوانكاری
    WHEN 'custom_1784479568_184' THEN 'swelling'   -- ئیلتهابی هه‌یه‌
    WHEN 'custom_1784641786_922' THEN 'cleaning'   -- بۆنی له‌ ده‌وی تێ
    WHEN 'custom_1784645802_486' THEN 'wisdom'   -- مراجعه‌ی ددانی ئه‌قلی
    WHEN 'custom_1784658331_683' THEN 'pain'   -- ددانی ده‌گری
    WHEN 'custom_1784725421_380' THEN 'wisdom'   -- كێشانی ددانی ئه‌قلی
    WHEN 'custom_1784826839_856' THEN 'surgery'   -- عه‌مه‌لیات
    WHEN 'custom_1784979743_749' THEN 'loose'   -- ددانی شل بووه‌
    WHEN 'custom_1784980454_247' THEN 'orthodontic'   -- تێل دانان
    WHEN 'custom_1785073171_817' THEN 'retreatment'   -- دووباره‌ ده‌ماربرین +پركردنه‌وه‌
    WHEN 'custom_1785077143_861' THEN 'post'   -- پۆست+پركردنه‌وه‌ +ده‌ماربرین
    WHEN 'custom_1785086580_480' THEN 'whitening'   -- لێزه‌ری جوانكاری
    WHEN 'custom_1785163765_696' THEN 'post'   -- post + filling
    WHEN 'custom_1785163848_865' THEN 'post'   -- post + filling
    WHEN 'custom_1785163907_582' THEN 'post'   -- پۆست+پركردنه وه
    WHEN 'custom_1785163993_278' THEN 'post'   -- post + filling
    WHEN 'custom_1785248168_353' THEN 'retainer'   -- ریتێنه‌ر
    WHEN 'custom_1785585964_880' THEN 'implant'   -- مراجعه‌ی چاندنی هه‌یه‌
    WHEN 'custom_1785613253_148' THEN 'crown'   -- كیفی شل بووه‌
    WHEN 'custom_1785675343_605' THEN 'retreatment'   -- دووباره ده‌ ماربرین + پركردنه وه ی مندال
    WHEN 'custom_1785675448_241' THEN 'root_canal'   -- f+pulpectomy
    WHEN 'custom_1785770926_821' THEN 'xray'   -- ئیشاعه‌ی گرتیه‌ دكتۆر ده‌یبینی
    WHEN 'custom_1785858303_157' THEN 'crown'   -- كێشه‌ی كیفی هه‌یه‌
    WHEN 'custom_1785917491_501' THEN 'retainer'   -- ریتێنه‌ر
    WHEN 'custom_1785925892_327' THEN 'denture'   -- تاخمی مشكیله‌ی هه‌یه‌
    WHEN 'custom_1785932629_239' THEN 'surgery'   -- داوده‌رهێنان
    WHEN 'custom_1785941224_614' THEN 'sensitive'   -- ددانی ده‌ته‌زی
    WHEN 'custom_1786024189_203' THEN 'filling'   -- مه‌واد ده‌رهاتیه‌
    WHEN 'custom_1786293211_398' THEN 'root_canal'   -- pulpotomy+filling
    WHEN 'custom_1786295837_548' THEN 'bleeding'   -- خوێنی راناوه‌ستی
    WHEN 'custom_1786530293_654' THEN 'followup'   -- چێك
    WHEN 'custom_1786534069_249' THEN 'impression'   -- قالب
    WHEN 'custom_1786534194_231' THEN 'sensitive'   -- هه‌ستیاره‌ به‌سارد و گه‌رمی
    WHEN 'custom_1786561793_949' THEN 'orthodontic'   -- مراجعه‌ی تێل
    WHEN 'custom_1786605562_210' THEN 'swelling'   -- ئیلتهابی هه‌یه‌
    WHEN 'custom_1786813497_297' THEN 'implant'   -- مراجعه‌ی چاندن
    WHEN 'custom_1786985350_338' THEN 'followup'   -- follow-up
    WHEN 'custom_1787061766_495' THEN 'denture'   -- تاخمی مشكیله‌ی هه‌یه‌
    WHEN 'custom_1787083163_369' THEN 'orthodontic'   -- ortho دانان
    WHEN 'custom_1787083239_867' THEN 'implant'   -- implant
    WHEN 'custom_1787225426_131' THEN 'filling_cosmetic'   -- پركردنه‌وه‌ به‌ جوانكاری
    WHEN 'custom_1788116804_429' THEN 'veneer'   -- hollywood
    WHEN 'custom_1790160716_265' THEN 'root_canal'   -- تەواوکردنی دەماربڕ
    WHEN 'custom_1790249029_139' THEN 'xray'   -- ئیشاعه‌
    WHEN 'custom_1790508204_213' THEN 'orthodontic'   -- ortho remove
    WHEN 'custom_1791978508_461' THEN 'followup'   -- مراجعه‌
END
WHERE icon IS NULL AND work_key IN ('cleaning', 'filling', 'extraction', 'root_canal', 'crown', 'bridge', 'braces', 'implant', 'whitening', 'checkup', 'custom_1778502794_510', 'custom_1778502806_310', 'custom_1778927378_473', 'custom_1778932618_480', 'custom_1778934415_844', 'custom_1779030206_332', 'custom_1779278460_115', 'custom_1779303139_853', 'custom_1779543642_866', 'custom_1779544591_258', 'custom_1780222568_141', 'custom_1780223186_725', 'custom_1780235916_713', 'custom_1783345705_191', 'custom_1783423470_647', 'custom_1783496923_889', 'custom_1783579223_660', 'custom_1783598499_305', 'custom_1783600656_552', 'custom_1783773280_717', 'custom_1783845250_113', 'custom_1783850541_681', 'custom_1783853735_323', 'custom_1783857831_674', 'custom_1783867049_265', 'custom_1783878554_120', 'custom_1783944290_143', 'custom_1783955156_344', 'custom_1783955240_974', 'custom_1783956404_840', 'custom_1784058143_136', 'custom_1784103026_701', 'custom_1784111419_606', 'custom_1784119357_183', 'custom_1784136163_174', 'custom_1784137646_542', 'custom_1784206516_117', 'custom_1784223669_355', 'custom_1784396263_578', 'custom_1784398698_101', 'custom_1784479568_184', 'custom_1784641786_922', 'custom_1784645802_486', 'custom_1784658331_683', 'custom_1784725421_380', 'custom_1784826839_856', 'custom_1784979743_749', 'custom_1784980454_247', 'custom_1785073171_817', 'custom_1785077143_861', 'custom_1785086580_480', 'custom_1785163765_696', 'custom_1785163848_865', 'custom_1785163907_582', 'custom_1785163993_278', 'custom_1785248168_353', 'custom_1785585964_880', 'custom_1785613253_148', 'custom_1785675343_605', 'custom_1785675448_241', 'custom_1785770926_821', 'custom_1785858303_157', 'custom_1785917491_501', 'custom_1785925892_327', 'custom_1785932629_239', 'custom_1785941224_614', 'custom_1786024189_203', 'custom_1786293211_398', 'custom_1786295837_548', 'custom_1786530293_654', 'custom_1786534069_249', 'custom_1786534194_231', 'custom_1786561793_949', 'custom_1786605562_210', 'custom_1786813497_297', 'custom_1786985350_338', 'custom_1787061766_495', 'custom_1787083163_369', 'custom_1787083239_867', 'custom_1787225426_131', 'custom_1788116804_429', 'custom_1790160716_265', 'custom_1790249029_139', 'custom_1790508204_213', 'custom_1791978508_461');
