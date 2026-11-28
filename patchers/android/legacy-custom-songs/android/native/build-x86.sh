#!/usr/bin/env bash
set -eu
if [ "$#" -ne 1 ]; then
    printf 'Usage: ANDROID_NDK_HOME=/path/to/ndk %s /path/to/libmuchocore_songs.so\n' "$0" >&2
    exit 2
fi
source_dir="$(cd "$(dirname "$0")" && pwd)"
ndk_compiler="${ANDROID_NDK_HOME:?Configure ANDROID_NDK_HOME}/toolchains/llvm/prebuilt/linux-x86_64/bin/i686-linux-android19-clang++"
"$ndk_compiler" -std=c++17 -O2 -Wall -Wextra -Werror -fvisibility=hidden \
    -fno-exceptions -fno-rtti -fno-stack-protector -nostdlib++ -fPIC -shared \
    -Wl,--no-undefined -Wl,--hash-style=sysv -Wl,-soname,libmuchocore_songs.so \
    "$source_dir/cocos_songs_x86.cpp" -ldl -llog -o "$1"
