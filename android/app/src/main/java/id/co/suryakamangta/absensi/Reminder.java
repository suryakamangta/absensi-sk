package id.co.suryakamangta.absensi;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;

import java.text.SimpleDateFormat;
import java.util.Calendar;
import java.util.Locale;
import java.util.TimeZone;

/**
 * Pengingat absen masuk: notifikasi tiap hari kerja pukul 07:50 WIB (10 menit sebelum jam masuk 08:00).
 * Tidak muncul di Sabtu/Minggu, tanggal merah (dikirim dari server), atau bila hari itu sudah absen masuk.
 * Dijadwalkan ulang setelah HP dinyalakan ulang (BOOT_COMPLETED) dan setiap kali alarm berbunyi.
 */
public class Reminder extends BroadcastReceiver {

    static final String PREF = "sk_reminder";
    static final String CHANNEL = "absen_masuk";
    static final int HOUR = 7, MINUTE = 50, NOTIF_ID = 750;
    static final TimeZone WIB = TimeZone.getTimeZone("Asia/Jakarta");

    static SharedPreferences prefs(Context c) { return c.getSharedPreferences(PREF, Context.MODE_PRIVATE); }

    static String today() {
        SimpleDateFormat f = new SimpleDateFormat("yyyy-MM-dd", Locale.US);
        f.setTimeZone(WIB);
        return f.format(Calendar.getInstance(WIB).getTime());
    }

    static boolean libur(Context c, Calendar cal) {
        int dow = cal.get(Calendar.DAY_OF_WEEK);
        if (dow == Calendar.SATURDAY || dow == Calendar.SUNDAY) return true;
        SimpleDateFormat f = new SimpleDateFormat("yyyy-MM-dd", Locale.US);
        f.setTimeZone(WIB);
        String d = f.format(cal.getTime());
        return ("," + prefs(c).getString("libur", "") + ",").contains("," + d + ",");
    }

    private static PendingIntent alarmIntent(Context c) {
        Intent i = new Intent(c, Reminder.class).setAction("id.co.suryakamangta.absensi.REMIND");
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 23 ? PendingIntent.FLAG_IMMUTABLE : 0);
        return PendingIntent.getBroadcast(c, 1, i, flags);
    }

    /** Jadwalkan alarm berikutnya (07:50 WIB hari kerja berikutnya), atau batalkan bila pengingat mati. */
    static void schedule(Context c) {
        AlarmManager am = (AlarmManager) c.getSystemService(Context.ALARM_SERVICE);
        if (am == null) return;
        PendingIntent pi = alarmIntent(c);
        if (!prefs(c).getBoolean("on", false)) { am.cancel(pi); return; }
        Calendar cal = Calendar.getInstance(WIB);
        cal.set(Calendar.HOUR_OF_DAY, HOUR);
        cal.set(Calendar.MINUTE, MINUTE);
        cal.set(Calendar.SECOND, 0);
        cal.set(Calendar.MILLISECOND, 0);
        if (cal.getTimeInMillis() <= System.currentTimeMillis()) cal.add(Calendar.DAY_OF_MONTH, 1);
        for (int n = 0; n < 14 && libur(c, cal); n++) cal.add(Calendar.DAY_OF_MONTH, 1);
        long at = cal.getTimeInMillis();
        try {
            if (Build.VERSION.SDK_INT >= 31 && !am.canScheduleExactAlarms()) am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pi);
            else if (Build.VERSION.SDK_INT >= 23) am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pi);
            else am.setExact(AlarmManager.RTC_WAKEUP, at, pi);
        } catch (SecurityException e) {
            if (Build.VERSION.SDK_INT >= 23) am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at, pi);
            else am.set(AlarmManager.RTC_WAKEUP, at, pi);
        }
    }

    static void ensureChannel(Context c) {
        if (Build.VERSION.SDK_INT >= 26) {
            NotificationManager nm = (NotificationManager) c.getSystemService(Context.NOTIFICATION_SERVICE);
            if (nm != null && nm.getNotificationChannel(CHANNEL) == null) {
                NotificationChannel ch = new NotificationChannel(CHANNEL, "Pengingat absen masuk", NotificationManager.IMPORTANCE_HIGH);
                ch.setDescription("Pengingat jam 07:50 bila belum absen masuk");
                nm.createNotificationChannel(ch);
            }
        }
    }

    @Override
    public void onReceive(Context c, Intent intent) {
        String a = intent.getAction();
        if (Intent.ACTION_BOOT_COMPLETED.equals(a) || "android.intent.action.MY_PACKAGE_REPLACED".equals(a)
                || Intent.ACTION_TIMEZONE_CHANGED.equals(a) || Intent.ACTION_TIME_CHANGED.equals(a)) {
            schedule(c);
            return;
        }
        SharedPreferences p = prefs(c);
        Calendar now = Calendar.getInstance(WIB);
        boolean sudahMasuk = today().equals(p.getString("masuk", ""));
        if (p.getBoolean("on", false) && !sudahMasuk && !libur(c, now) && now.get(Calendar.HOUR_OF_DAY) < 9) notify(c);
        schedule(c);
    }

    @SuppressWarnings("deprecation")
    private void notify(Context c) {
        NotificationManager nm = (NotificationManager) c.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null) return;
        ensureChannel(c);
        Intent open = new Intent(c, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= 23 ? PendingIntent.FLAG_IMMUTABLE : 0);
        PendingIntent pi = PendingIntent.getActivity(c, 2, open, flags);
        Notification.Builder b = Build.VERSION.SDK_INT >= 26 ? new Notification.Builder(c, CHANNEL) : new Notification.Builder(c);
        b.setSmallIcon(R.mipmap.ic_launcher)
                .setContentTitle("Jangan lupa absen masuk!")
                .setContentText("10 menit lagi jam 08:00. Tekan untuk absen sekarang.")
                .setAutoCancel(true)
                .setContentIntent(pi);
        if (Build.VERSION.SDK_INT < 26) b.setPriority(Notification.PRIORITY_HIGH).setDefaults(Notification.DEFAULT_ALL);
        try { nm.notify(NOTIF_ID, b.build()); } catch (SecurityException ignored) { }
    }
}
