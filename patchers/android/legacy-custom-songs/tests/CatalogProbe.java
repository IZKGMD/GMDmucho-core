package space.muchogdps.legacysongs;

import java.io.File;
import java.io.IOException;
import java.io.RandomAccessFile;
import java.nio.file.Files;
import java.util.Arrays;

public final class CatalogProbe {
    public static void main(String[] args) throws Exception {
        boolean shared = args.length > 2 && args[2].equals("shared");
        CatalogClient client = new CatalogClient(args[0], shared, new File(args[1]));
        CatalogClient.Page first = client.catalog(0);
        if (first.tracks.size() != 16 || first.nextAfter != 16) {
            throw new AssertionError("First page");
        }
        CatalogClient.Page second = client.catalog(first.nextAfter);
        if (second.tracks.size() != 2 || second.nextAfter != 0 || second.tracks.get(0).id != 17) {
            throw new AssertionError("Second page and track moderation");
        }
        File firstFile = client.download(1);
        byte[] original = Files.readAllBytes(firstFile.toPath());
        if (firstFile.length() != first.tracks.get(0).bytes) {
            throw new AssertionError("Downloaded file size");
        }
        if (!client.download(1).equals(firstFile)) {
            throw new AssertionError("Cache hit");
        }
        try (RandomAccessFile corrupted = new RandomAccessFile(firstFile, "rw")) {
            int initial = corrupted.readUnsignedByte();
            corrupted.seek(0);
            corrupted.writeByte(initial ^ 255);
        }
        if (!client.download(1).equals(firstFile) ||
                !Arrays.equals(original, Files.readAllBytes(firstFile.toPath()))) {
            throw new AssertionError("Corrupted cache recovery");
        }
        CatalogClient secondPlayer = new CatalogClient(args[0], shared, new File(args[1], "second-player"));
        CatalogClient.LevelSong byLevel = secondPlayer.downloadForLevel(42);
        if (byLevel == null || byLevel.track.id != 1 || firstFile.equals(byLevel.file) ||
                !Arrays.equals(original, Files.readAllBytes(byLevel.file.toPath()))) {
            throw new AssertionError("Level song resolution and download with a clean cache");
        }
        if (!secondPlayer.download(1).equals(byLevel.file) || secondPlayer.downloadForLevel(43) != null) {
            throw new AssertionError("Same cache entry and no custom song for a stock level");
        }
        for (int unavailable : new int[] {44, 45, 46}) {
            try {
                secondPlayer.downloadForLevel(unavailable);
                throw new AssertionError("Unexpected song for level " + unavailable);
            } catch (IOException expected) {
                if (!"Server returned HTTP 404".equals(expected.getMessage())) {
                    throw expected;
                }
            }
        }
        System.out.println("CATALOG_AND_MP3_CACHE_OK " + firstFile);
        System.out.println("LEVEL_SONG_DOWNLOAD_AND_MODERATION_OK " + byLevel.file);
    }
}
