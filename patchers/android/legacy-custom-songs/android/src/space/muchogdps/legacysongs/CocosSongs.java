package space.muchogdps.legacysongs;

import android.app.Activity;
import android.util.Log;
import android.widget.Toast;
import java.io.File;
import java.io.InputStream;
import java.lang.ref.WeakReference;
import java.util.Collections;
import java.util.List;
import java.util.Properties;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** Network and cache for the Cocos2d screens. All Cocos nodes live on the GL thread. */
public final class CocosSongs {
    private static final int ROWS = 5;
    private static final int MORE = 8;
    private static final int STOCK = 9;
    private static final ExecutorService NETWORK = Executors.newFixedThreadPool(2);
    private static final String TAG = "MuchoCoreCocos";
    private static final TrackState PENDING = new TrackState(0, "Loading song...", null);
    private static final TrackState STOCK_SONG = new TrackState(0, "", null);
    private static final TrackState UNAVAILABLE = new TrackState(0, "Song unavailable", null);
    private static final ConcurrentHashMap<Integer, TrackState> levels =
            new ConcurrentHashMap<Integer, TrackState>();
    private static final ConcurrentHashMap<Integer, TrackState> songs =
            new ConcurrentHashMap<Integer, TrackState>();

    private static volatile boolean enabled;
    private static volatile boolean rendering;
    private static volatile WeakReference<Activity> activityRef;
    private static CatalogClient client;
    private static volatile Page page = new Page("Loading...", Collections.<CatalogClient.Track>emptyList(), 0, 0);
    private static volatile int catalogRevision;
    private static volatile int selected = -1;
    private static volatile int request;
    private static volatile int bindingKey = -1;

    private CocosSongs() {}

    public static void attach(Activity activity) {
        activityRef = new WeakReference<Activity>(activity);
        try (InputStream configuration = activity.getAssets().open("muchocore-legacy.properties")) {
            Properties properties = new Properties();
            properties.load(configuration);
            client = new CatalogClient(properties.getProperty("server"),
                    "shared".equals(properties.getProperty("mode")),
                    new File(activity.getCacheDir(), "muchocore-legacy-songs"));
            System.loadLibrary("muchocore_songs");
            enabled = true;
        } catch (Exception | LinkageError error) {
            Log.e(TAG, "Cocos2d integration could not start", error);
            stopStartup();
        }
    }

    public static boolean start() {
        try {
            rendering = enabled && nativeStart();
        } catch (LinkageError error) {
            Log.e(TAG, "Native plugin could not initialize", error);
        }
        if (!rendering) stopStartup();
        return rendering;
    }

    private static synchronized void stopStartup() {
        rendering = false;
        final Activity activity = activityRef == null ? null : activityRef.get();
        activityRef = null;
        if (activity == null) return;
        activity.runOnUiThread(new Runnable() {
            @Override public void run() {
                Toast.makeText(activity, "MuchoCore cannot start: Custom Songs plugin is unavailable.",
                        Toast.LENGTH_LONG).show();
                activity.finish();
            }
        });
    }

    public static boolean frame() {
        if (rendering) nativeFrame();
        return rendering;
    }

    private static native boolean nativeStart();
    private static native void nativeFrame();
    private static native String nativeMusicPath();

    public static String musicPath(String original) {
        if (!enabled || original == null || !original.endsWith(".mp3") ||
                original.endsWith("menuLoop.mp3") || original.endsWith("StayInsideMe.mp3")) {
            return original;
        }
        String replacement = nativeMusicPath();
        return replacement == null ? original : replacement;
    }

    static void open(int objectId) {
        bindingKey = objectId;
        selected = -1;
        loadPage(0);
    }

    private static void loadPage(final int after) {
        final int job = ++request;
        update(new Page("Loading...", Collections.<CatalogClient.Track>emptyList(), 0, 0), job);
        NETWORK.submit(new Runnable() {
            @Override public void run() {
                try {
                    CatalogClient.Page result = client.catalog(after);
                    update(new Page(result.tracks.isEmpty() ? "No approved songs" : "Choose a song",
                            result.tracks, 0, result.nextAfter), job);
                } catch (Exception error) {
                    update(new Page("Catalog unavailable", Collections.<CatalogClient.Track>emptyList(), 0, 0), job);
                    Log.w(TAG, "Catalog request failed", error);
                }
            }
        });
    }

    private static synchronized void update(Page result, int job) {
        if (job != request) return;
        page = result;
        catalogRevision++;
    }

    static synchronized void action(final int row, int drawnRevision) {
        if (drawnRevision != catalogRevision) return;
        Page current = page;
        if (row == STOCK) {
            request++;
            selected = 0;
            return;
        }
        if (row == MORE) {
            if (current.start + ROWS < current.tracks.size()) {
                update(new Page("Choose a song", current.tracks, current.start + ROWS, current.next), request);
            } else {
                loadPage(current.next);
            }
            return;
        }
        final int index = current.start + row;
        if (row < 0 || row >= ROWS || index >= current.tracks.size()) return;
        final CatalogClient.Track track = current.tracks.get(index);
        final int job = ++request;
        update(new Page("Downloading...", Collections.<CatalogClient.Track>emptyList(), 0, 0), job);
        NETWORK.submit(new Runnable() {
            @Override public void run() {
                try {
                    File mp3 = client.download(track.id);
                    songs.put(track.id, new TrackState(track.id, label(track), mp3.getAbsolutePath()));
                    if (job == request) selected = track.id;
                } catch (Exception error) {
                    update(new Page("Download failed", Collections.<CatalogClient.Track>emptyList(), 0, 0), job);
                    Log.w(TAG, "Song download failed", error);
                }
            }
        });
    }

    static int revision() {
        return catalogRevision;
    }

    static String[] rows() {
        Page current = page;
        String[] rows = new String[ROWS + 2];
        rows[0] = current.status;
        for (int i = 0; i < ROWS; i++) {
            int index = current.start + i;
            if (index < current.tracks.size()) rows[i + 1] = label(current.tracks.get(index));
        }
        rows[ROWS + 1] = current.start + ROWS < current.tracks.size() || current.next > 0
                ? "Next" : "Refresh";
        return rows;
    }

    static int selection(int objectId) {
        if (objectId != bindingKey) return -1;
        int value = selected;
        selected = -1;
        return value;
    }

    static String title(int levelId, int songId) {
        TrackState state = songId > 0 ? song(songId) : level(levelId);
        return state.title;
    }

    static String path(int levelId, int songId) {
        TrackState state = songId > 0 ? song(songId) : level(levelId);
        return state.path;
    }

    private static TrackState song(final int songId) {
        TrackState existing = songs.putIfAbsent(songId, PENDING);
        if (existing != null) return existing;
        NETWORK.submit(new Runnable() {
            @Override public void run() {
                try {
                    CatalogClient.LevelSong result = client.downloadWithMetadata(songId);
                    songs.put(songId, new TrackState(result.track.id, label(result.track), result.file.getAbsolutePath()));
                } catch (Exception error) {
                    songs.put(songId, UNAVAILABLE);
                    Log.w(TAG, "Song metadata request failed", error);
                }
            }
        });
        return PENDING;
    }

    private static TrackState level(final int levelId) {
        if (levelId <= 0) return STOCK_SONG;
        TrackState existing = levels.putIfAbsent(levelId, PENDING);
        if (existing != null) return existing;
        NETWORK.submit(new Runnable() {
            @Override public void run() {
                try {
                    CatalogClient.LevelSong result = client.downloadForLevel(levelId);
                    if (result == null) {
                        levels.put(levelId, STOCK_SONG);
                    } else {
                        TrackState state = new TrackState(result.track.id, label(result.track), result.file.getAbsolutePath());
                        songs.put(result.track.id, state);
                        levels.put(levelId, state);
                    }
                } catch (Exception error) {
                    levels.put(levelId, UNAVAILABLE);
                    Log.w(TAG, "Level song request failed", error);
                }
            }
        });
        return PENDING;
    }

    private static String label(CatalogClient.Track track) {
        String title = track.title.replaceAll("[\\p{Cc}\\p{Cf}]", " ").trim();
        String artist = track.artist.replaceAll("[\\p{Cc}\\p{Cf}]", " ").trim();
        String text = artist.isEmpty() ? title : title + " / " + artist;
        int length = text.codePointCount(0, text.length());
        return length > 44 ? text.substring(0, text.offsetByCodePoints(0, 43)) + "..." : text;
    }

    private static final class Page {
        final String status;
        final List<CatalogClient.Track> tracks;
        final int start;
        final int next;

        Page(String status, List<CatalogClient.Track> tracks, int start, int next) {
            this.status = status;
            this.tracks = tracks;
            this.start = start;
            this.next = next;
        }
    }

    private static final class TrackState {
        final int id;
        final String title;
        final String path;

        TrackState(int id, String title, String path) {
            this.id = id;
            this.title = title;
            this.path = path;
        }
    }
}
