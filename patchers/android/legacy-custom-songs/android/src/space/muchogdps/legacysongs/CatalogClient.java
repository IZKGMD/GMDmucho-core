package space.muchogdps.legacysongs;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileNotFoundException;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.ArrayList;
import java.util.List;

final class CatalogClient {
    private static final String API = "/api/v2/music-library.php";
    private static final String PLUGIN_API = "/api/plugin/legacy-songs/v1";
    private static final int MAX_JSON_BYTES = 64 * 1024;
    private static final int MAX_TRACK_BYTES = 64 * 1024 * 1024;
    private static final char[] HEX = "0123456789abcdef".toCharArray();

    static final class Track {
        final int id;
        final String title;
        final String artist;
        final int bytes;
        final String path;

        Track(JSONObject data, String mount, boolean shared) throws JSONException, IOException {
            id = data.getInt("id");
            title = data.getString("title");
            artist = data.getString("artist");
            bytes = data.getInt("bytes");
            path = data.getString("url");
            int separator = path.lastIndexOf('/');
            String folder = path.substring(0, separator + 1);
            String filename = path.substring(separator + 1);
            boolean musicFolder = folder.equals(mount + "/music/") ||
                    (shared && folder.equals(mount + "/public/assets/music/"));
            if (id <= 0 || bytes <= 0 || bytes > MAX_TRACK_BYTES || !musicFolder ||
                    !filename.matches("[A-Za-z0-9][A-Za-z0-9_-]{0,99}[.]mp3")) {
                throw new IOException("Invalid track metadata");
            }
        }
    }

    static final class Page {
        final List<Track> tracks;
        final int nextAfter;

        Page(List<Track> tracks, int nextAfter) {
            this.tracks = tracks;
            this.nextAfter = nextAfter;
        }
    }

    static final class LevelSong {
        final Track track;
        final File file;

        LevelSong(Track track, File file) {
            this.track = track;
            this.file = file;
        }
    }

    private final URL origin;
    private final String mount;
    private final boolean shared;
    private final File cache;

    CatalogClient(String server, boolean shared, File cacheRoot) throws IOException {
        origin = new URL(server);
        this.shared = shared;
        mount = origin.getPath().replaceAll("/$", "");
        String host = origin.getHost();
        boolean developmentHost = host.equals("localhost") || host.equals("127.0.0.1") || host.equals("10.0.2.2");
        if ((!origin.getProtocol().equals("https") && !(origin.getProtocol().equals("http") && developmentHost)) ||
                host.length() == 0 || origin.getUserInfo() != null || origin.getQuery() != null || origin.getRef() != null ||
                (!shared && !mount.isEmpty()) || !mount.matches("(?:/[A-Za-z0-9_-]+)*")) {
            throw new IOException("Use an HTTPS MuchoCore server URL");
        }
        cache = new File(cacheRoot, toHex(digest().digest(origin.toExternalForm().getBytes("UTF-8"))));
    }

    String host() {
        return origin.getHost();
    }

    private JSONObject request(String operation, String query) throws IOException, JSONException {
        String parameters = "?op=" + operation + "&" + query;
        if (shared) {
            return json(mount + "/legacy-songs.php" + parameters);
        }
        try {
            return json(API + parameters);
        } catch (FileNotFoundException | JSONException unavailable) {
            return json(PLUGIN_API + "/" + operation + "?" + query);
        }
    }

    Page catalog(int after) throws IOException, JSONException {
        JSONObject result = request("catalog", "after=" + after);
        JSONArray items = result.getJSONArray("tracks");
        if (items.length() > 16) {
            throw new IOException("Invalid catalog length");
        }
        List<Track> tracks = new ArrayList<Track>();
        int previous = after;
        for (int i = 0; i < items.length(); i++) {
            Track track = new Track(items.getJSONObject(i), mount, shared);
            if (track.id <= previous) {
                throw new IOException("Invalid catalog order");
            }
            tracks.add(track);
            previous = track.id;
        }
        int next = result.isNull("next_after") ? 0 : result.getInt("next_after");
        if (next != 0 && (next <= after || next < previous)) {
            throw new IOException("Invalid catalog cursor");
        }
        return new Page(tracks, next);
    }

    File download(int id) throws IOException, JSONException {
        return downloadWithMetadata(id).file;
    }

    LevelSong downloadWithMetadata(int id) throws IOException, JSONException {
        JSONObject response = request("track", "id=" + id).getJSONObject("track");
        Track track = new Track(response, mount, shared);
        if (track.id != id) {
            throw new IOException("Server returned a different song");
        }
        return new LevelSong(track, download(track, response));
    }

    LevelSong downloadForLevel(int levelId) throws IOException, JSONException {
        if (levelId <= 0) {
            throw new IOException("Enter a positive level ID");
        }
        JSONObject level = request("level", "id=" + levelId);
        if (level.getInt("level_id") != levelId || !level.has("track")) {
            throw new IOException("Invalid level song response");
        }
        if (level.isNull("track")) {
            return null;
        }
        JSONObject response = level.getJSONObject("track");
        Track track = new Track(response, mount, shared);
        return new LevelSong(track, download(track, response));
    }

    private File download(Track track, JSONObject response) throws IOException, JSONException {
        String digest = response.getString("sha256");
        if (!digest.matches("[a-f0-9]{64}")) {
            throw new IOException("Invalid song checksum");
        }
        if (!cache.isDirectory() && !cache.mkdirs() && !cache.isDirectory()) {
            throw new IOException("Song cache unavailable");
        }
        File target = new File(cache, track.id + "-" + digest + ".mp3");
        if (matches(target, track.bytes, digest)) {
            return target;
        }

        File pending = File.createTempFile("track-", ".part", cache);
        try {
            HttpURLConnection connection = connect(track.path);
            try {
                String type = connection.getContentType();
                if (type == null || !(type.startsWith("audio/mpeg") || type.startsWith("audio/mp3"))) {
                    throw new IOException("Server did not return an MP3");
                }
                int expected = connection.getContentLength();
                if (expected >= 0 && expected != track.bytes) {
                    throw new IOException("Unexpected song length");
                }
                MessageDigest checksum = digest();
                try (InputStream input = connection.getInputStream();
                     FileOutputStream output = new FileOutputStream(pending)) {
                    int size = copy(input, output, track.bytes, checksum);
                    if (size != track.bytes || !digest.equals(toHex(checksum.digest()))) {
                        throw new IOException("Song did not match its checksum");
                    }
                }
            } finally {
                connection.disconnect();
            }
            if (!pending.renameTo(target) && !matches(target, track.bytes, digest)) {
                throw new IOException("Unable to publish song cache file");
            }
            return target;
        } finally {
            pending.delete();
        }
    }

    private static boolean matches(File file, int bytes, String sha256) throws IOException {
        if (!file.isFile() || file.length() != bytes) {
            return false;
        }
        MessageDigest checksum = digest();
        try (FileInputStream input = new FileInputStream(file)) {
            return copy(input, null, bytes, checksum) == bytes && sha256.equals(toHex(checksum.digest()));
        }
    }

    private JSONObject json(String path) throws IOException, JSONException {
        HttpURLConnection connection = connect(path);
        try (InputStream input = connection.getInputStream()) {
            ByteArrayOutputStream output = new ByteArrayOutputStream();
            copy(input, output, MAX_JSON_BYTES, null);
            JSONObject data = new JSONObject(output.toString("UTF-8"));
            if (data.getInt("version") != 1) {
                throw new IOException("Unsupported catalog version");
            }
            return data;
        } finally {
            connection.disconnect();
        }
    }

    private HttpURLConnection connect(String path) throws IOException {
        HttpURLConnection connection = (HttpURLConnection) new URL(origin, path).openConnection();
        connection.setInstanceFollowRedirects(false);
        connection.setConnectTimeout(6000);
        connection.setReadTimeout(9000);
        connection.setUseCaches(false);
        try {
            int status = connection.getResponseCode();
            if (status == HttpURLConnection.HTTP_NOT_FOUND) {
                throw new FileNotFoundException("Server returned HTTP 404");
            }
            if (status != HttpURLConnection.HTTP_OK) {
                throw new IOException("Server returned HTTP " + status);
            }
            return connection;
        } catch (IOException error) {
            connection.disconnect();
            throw error;
        }
    }

    private static int copy(InputStream input, OutputStream output, int limit, MessageDigest digest) throws IOException {
        byte[] block = new byte[8192];
        int size = 0;
        int read;
        while ((read = input.read(block)) != -1) {
            size += read;
            if (size > limit || Thread.currentThread().isInterrupted()) {
                throw new IOException("Transfer exceeded its limit or was cancelled");
            }
            if (output != null) {
                output.write(block, 0, read);
            }
            if (digest != null) {
                digest.update(block, 0, read);
            }
        }
        return size;
    }

    private static MessageDigest digest() {
        try {
            return MessageDigest.getInstance("SHA-256");
        } catch (NoSuchAlgorithmException error) {
            throw new AssertionError(error);
        }
    }

    private static String toHex(byte[] bytes) {
        char[] converted = new char[bytes.length * 2];
        for (int i = 0; i < bytes.length; i++) {
            int value = bytes[i] & 255;
            converted[2 * i] = HEX[value >>> 4];
            converted[2 * i + 1] = HEX[value & 15];
        }
        return new String(converted);
    }
}
