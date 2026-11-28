#include <jni.h>

extern "C" void* dlopen(const char*, int);
extern "C" void* dlsym(void*, const char*);
extern "C" int __android_log_write(int, const char*, const char*);
extern "C" void* malloc(unsigned);
extern "C" void free(void*);
extern "C" unsigned strlen(const char*);
extern "C" int strcmp(const char*, const char*);
extern "C" char* strstr(const char*, const char*);
extern "C" void* memcpy(void*, const void*, unsigned);
extern "C" int snprintf(char*, unsigned, const char*, ...);
extern "C" void* mmap(void*, unsigned, int, int, int, long);
extern "C" int mprotect(void*, unsigned, int);

namespace {
using Node = void;
struct Size { float width, height; };
struct Color { unsigned char r, g, b, a; };
struct Selector { void (*method)(Node*, Node*); int adjustment; };

constexpr int tabTag = 0x4d430001;
constexpr int paneTag = 0x4d430002;
constexpr int titleTag = 0x4d430003;
// Button IDs are also used by CocosSongs.action.
constexpr int moreTag = 8;
constexpr int stockTag = 9;
constexpr int closeTag = 10;
constexpr int findTag = 11;
constexpr int catalogTag = 12;
constexpr int switchSourceTag = 13;
constexpr int eraseTag = 14;
constexpr int searchTag = 15;
constexpr int trackActionTag = 16;
constexpr int firstDigitTag = 20;
constexpr int layerDepth = 5;
// FLAlertLayer swallows touches at INT_MIN + 1. Its nested song menus
// must receive the touch before it does.
constexpr int songMenuTouchPriority = -2147483647 - 1;
constexpr int bindingsMax = 1024;
constexpr unsigned pageBytes = 4096;
constexpr int protectionRead = 1, protectionWrite = 2, protectionExec = 4;
constexpr int privateAnon = 0x22;
constexpr const char* savedSongKey = "mucho_song_id";

// Layout for the fingerprinted GD 1.81 build 20 x86 executable.
constexpr unsigned runningSceneOffset = 0x9c;
constexpr unsigned levelInfoLevelOffset = 0x13c;
constexpr unsigned editLevelOffset = 0x124;
constexpr unsigned editorPauseEditorOffset = 0x1a8;
constexpr unsigned settingsObjectOffset = 0x1dc;
constexpr unsigned settingsLabelOffset = 0x1e0;

struct Api {
    Node* (*director)();
    Node* (*children)(Node*);
    Node* (*child)(Node*, unsigned);
    unsigned (*count)(Node*);
    Node* (*tagNode)(Node*, int);
    void (*add)(Node*, Node*, int, int);
    void (*remove)(Node*, int, bool);
    bool (*visible)(Node*);
    void (*setVisible)(Node*, bool);
    void (*position)(Node*, float, float);
    float (*x)(Node*);
    float (*y)(Node*);
    const Size* (*size)(Node*);
    Node* (*parent)(Node*);
    int (*tag)(Node*);
    Node* (*menu)();
    Node* (*item)(Node*, Node*, Selector);
    void (*priority)(Node*, int);
    Node* (*label)(const char*, const char*, float);
    void (*text)(Node*, const char*);
    Node* (*shade)(const Color*, float, float);
    Node* (*editorOfSettings)(Node*);
    Node* (*editorLevel)(Node*);
    Node* (*editorSettings)(Node*);
    Node* (*playLevel)(Node*);
    int (*levelId)(Node*);
    int (*objectId)(Node*);
    int (*audioTrack)(Node*);
    int (*settingsTrack)(Node*);
    int (*readInteger)(void*, const char*);
    void (*writeInteger)(void*, const char*, int);
    const char* (*stockTitle)(int);
    const char* (*bitmapLabelText)(Node*);
    void* bitmapLabelType;
    void* settingsType;
    void* editorType;
    void* levelPageType;
    void* editLevelType;
    void* editorPauseType;
    void* playType;
    void* pauseType;
};

Api api{};
bool initialized;
bool ready;
JavaVM* vm;
jclass bridge;
jmethodID javaOpen, javaCancel, javaAction, javaRows, javaRevision, javaSelection, javaTitle, javaPath;
unsigned frames;
Node* popupOwner;
int popupRevision = -1;
Node* closeRequested;
struct Binding { int objectId, songId, stockSong; };
Binding bindings[bindingsMax]{};
unsigned nextBinding;
int uploadingSong = -1;
void (*originalEncode)(Node*, void*);
void (*originalLoad)(Node*, void*);
void (*originalUpload)(Node*, Node*);
void (*originalRequest)(Node*, const char**, const char**, const char**, int);
bool installHooks(void* game);

template <typename T>
bool symbol(void* game, T& slot, const char* name) {
    slot = reinterpret_cast<T>(dlsym(game, name));
    if (!slot) __android_log_write(6, "MuchoCoreCocos", name);
    return slot != nullptr;
}

bool resolve() {
    void* game = dlopen("libcocos2dcpp.so", 2);
    if (!game) return false;
#define BIND(slot, name) if (!symbol(game, api.slot, name)) return false
    BIND(director, "_ZN7cocos2d10CCDirector14sharedDirectorEv");
    // KitKat's dlsym skips weak exports. The patcher has already fingerprinted
    // the GD 1.81 x86 image, where sharedDirector is at virtual address 0x303660.
    const unsigned imageBase = reinterpret_cast<unsigned>(api.director) - 0x303660;
#define BIND_WEAK(slot, name, address) \
    api.slot = reinterpret_cast<decltype(api.slot)>(dlsym(game, name)); \
    if (!api.slot) api.slot = reinterpret_cast<decltype(api.slot)>(imageBase + address)
    BIND(children, "_ZN7cocos2d6CCNode11getChildrenEv");
    BIND(child, "_ZN7cocos2d7CCArray13objectAtIndexEj");
    BIND(count, "_ZNK7cocos2d7CCArray5countEv");
    BIND(tagNode, "_ZN7cocos2d6CCNode13getChildByTagEi");
    BIND(add, "_ZN7cocos2d6CCNode8addChildEPS0_ii");
    BIND(remove, "_ZN7cocos2d6CCNode16removeChildByTagEib");
    BIND(visible, "_ZN7cocos2d6CCNode9isVisibleEv");
    BIND(setVisible, "_ZN7cocos2d6CCNode10setVisibleEb");
    BIND(position, "_ZN7cocos2d6CCNode11setPositionEff");
    BIND(x, "_ZN7cocos2d6CCNode12getPositionXEv");
    BIND(y, "_ZN7cocos2d6CCNode12getPositionYEv");
    BIND(size, "_ZNK7cocos2d6CCNode14getContentSizeEv");
    BIND(parent, "_ZN7cocos2d6CCNode9getParentEv");
    BIND(tag, "_ZNK7cocos2d6CCNode6getTagEv");
    BIND(menu, "_ZN7cocos2d6CCMenu6createEv");
    BIND(item, "_ZN7cocos2d15CCMenuItemLabel6createEPNS_6CCNodeEPNS_8CCObjectEMS3_FvS4_E");
    BIND(priority, "_ZN7cocos2d6CCMenu18setHandlerPriorityEi");
    BIND(label, "_ZN7cocos2d10CCLabelTTF6createEPKcS2_f");
    BIND(text, "_ZN7cocos2d10CCLabelTTF9setStringEPKc");
    BIND(shade, "_ZN7cocos2d12CCLayerColor6createERKNS_10_ccColor4BEff");
    BIND_WEAK(editorOfSettings, "_ZNK18LevelSettingsLayer14getEditorLayerEv", 0x208960);
    BIND_WEAK(editorLevel, "_ZNK16LevelEditorLayer8getLevelEv", 0x2083b0);
    BIND_WEAK(editorSettings, "_ZNK16LevelEditorLayer16getLevelSettingsEv", 0x2083c0);
    BIND_WEAK(playLevel, "_ZNK9PlayLayer8getLevelEv", 0x1cabf0);
    BIND_WEAK(levelId, "_ZNK11GJGameLevel10getLevelIDEv", 0x2484b0);
    BIND_WEAK(objectId, "_ZNK11GJGameLevel7getM_IDEv", 0x248cf0);
    BIND_WEAK(audioTrack, "_ZNK11GJGameLevel13getAudioTrackEv", 0x248540);
    BIND_WEAK(settingsTrack, "_ZNK19LevelSettingsObject13getAudioTrackEv", 0x208810);
    BIND(readInteger, "_ZN13DS_Dictionary16getIntegerForKeyEPKc");
    BIND(writeInteger, "_ZN13DS_Dictionary16setIntegerForKeyEPKci");
    BIND(bitmapLabelText, "_ZN7cocos2d13CCLabelBMFont9getStringEv");
    BIND_WEAK(bitmapLabelType, "_ZTVN7cocos2d13CCLabelBMFontE", 0x83ada0);
    BIND(stockTitle, "_ZN10LevelTools13getAudioTitleEi");
    BIND_WEAK(editorType, "_ZTV16LevelEditorLayer", 0x823380);
    BIND_WEAK(settingsType, "_ZTV18LevelSettingsLayer", 0x823c40);
    BIND_WEAK(levelPageType, "_ZTV14LevelInfoLayer", 0x826700);
    BIND_WEAK(editLevelType, "_ZTV14EditLevelLayer", 0x826ae0);
    BIND_WEAK(editorPauseType, "_ZTV16EditorPauseLayer", 0x82a120);
    BIND_WEAK(playType, "_ZTV9PlayLayer", 0x81f2a0);
    BIND_WEAK(pauseType, "_ZTV10PauseLayer", 0x824ec0);
#undef BIND_WEAK
#undef BIND
    return installHooks(game);
}

Node* field(Node* node, unsigned offset) {
    return node ? *reinterpret_cast<Node**>(static_cast<unsigned char*>(node) + offset) : nullptr;
}

Node* find(Node* root, void* type, int depth = layerDepth) {
    if (!root || !api.visible(root)) return nullptr;
    if (*reinterpret_cast<void***>(root) == static_cast<void**>(type) + 2) return root;
    if (!depth) return nullptr;
    Node* list = api.children(root);
    if (!list) return nullptr;
    const unsigned count = api.count(list);
    if (count > 128) return nullptr;
    for (unsigned index = count; index > 0; --index) {
        Node* result = find(api.child(list, index - 1), type, depth - 1);
        if (result) return result;
    }
    return nullptr;
}

Node* stockLabel(Node* root, const char* name, int depth = layerDepth) {
    if (!root || !name || !*name) return nullptr;
    if (*reinterpret_cast<void***>(root) == static_cast<void**>(api.bitmapLabelType) + 2) {
        const char* text = api.bitmapLabelText(root);
        return text && strcmp(text, name) == 0 ? root : nullptr;
    }
    if (!depth) return nullptr;
    Node* list = api.children(root);
    if (!list) return nullptr;
    const unsigned count = api.count(list);
    if (count > 128) return nullptr;
    for (unsigned index = 0; index < count; ++index) {
        if (Node* label = stockLabel(api.child(list, index), name, depth - 1)) return label;
    }
    return nullptr;
}

Node* editorLevel(Node* settings) {
    Node* editor = api.editorOfSettings(settings);
    return editor ? api.editorLevel(editor) : nullptr;
}

int boundSong(Node* level, int stock) {
    const int objectId = api.objectId(level);
    for (const Binding& binding : bindings) {
        if (binding.songId > 0 && binding.objectId == objectId && binding.stockSong == stock) {
            return binding.songId;
        }
    }
    return 0;
}

void bind(Node* level, int selectedSong, int stock) {
    const int objectId = api.objectId(level);
    for (Binding& binding : bindings) {
        if (binding.objectId == objectId && binding.songId > 0) {
            binding = {objectId, selectedSong, stock};
            return;
        }
    }
    if (selectedSong > 0) {
        bindings[nextBinding] = {objectId, selectedSong, stock};
        nextBinding = (nextBinding + 1) % bindingsMax;
    }
}

// The four intercepted functions share a four-push prologue and the same
// PC thunk. We reconstruct the original PIC register before jumping past it.
struct Hook {
    const char* symbol;
    unsigned thunkDisplacement;
    void* entry;
    void* replacement;
    void* trampoline;
};

void writeWord(unsigned char* bytes, unsigned value) {
    for (unsigned i = 0; i < 4; ++i) bytes[i] = (value >> (8 * i)) & 255;
}

void jump(unsigned char* bytes, void* destination) {
    bytes[0] = 0xe9;
    writeWord(bytes + 1, reinterpret_cast<unsigned>(destination) -
                         reinterpret_cast<unsigned>(bytes + 5));
}

void* pageFor(void* address) {
    return reinterpret_cast<void*>(reinterpret_cast<unsigned>(address) & ~(pageBytes - 1));
}

bool prepareHook(void* game, Hook& hook, unsigned char* trampoline) {
    auto* entry = static_cast<unsigned char*>(dlsym(game, hook.symbol));
    const unsigned char prologue[] = {0x55, 0x57, 0x56, 0x53, 0xe8};
    if (!entry || pageFor(entry) != pageFor(entry + 8)) return false;
    for (unsigned i = 0; i < sizeof(prologue); ++i) {
        if (entry[i] != prologue[i]) return false;
    }
    for (unsigned i = 0; i < 4; ++i) {
        if (entry[5 + i] != ((hook.thunkDisplacement >> (8 * i)) & 255)) return false;
        trampoline[i] = prologue[i];
    }
    trampoline[4] = 0xbb;
    writeWord(trampoline + 5, reinterpret_cast<unsigned>(entry + 9));
    jump(trampoline + 9, entry + 9);
    hook.entry = entry;
    hook.trampoline = trampoline;
    return true;
}

void saveLevel(Node* level, void* dictionary) {
    originalEncode(level, dictionary);
    const int selected = boundSong(level, api.audioTrack(level));
    if (selected > 0) api.writeInteger(dictionary, savedSongKey, selected);
}

void loadLevel(Node* level, void* dictionary) {
    originalLoad(level, dictionary);
    const int selected = api.readInteger(dictionary, savedSongKey);
    if (selected > 0) bind(level, selected, api.audioTrack(level));
}

void uploadLevel(Node* manager, Node* level) {
    const int previous = uploadingSong;
    uploadingSong = level ? boundSong(level, api.audioTrack(level)) : -1;
    originalUpload(manager, level);
    uploadingSong = previous;
}

void processRequest(Node* manager, const char** url, const char** body, const char** tag, int type) {
    if (uploadingSong <= 0 || type != 1 || !url || !*url || !body || !*body ||
        !strstr(*url, "/database/uploadGJLevel.php")) {
        originalRequest(manager, url, body, tag, type);
        return;
    }
    // The original reads these strings by value and copies the body into
    // its request vector before returning.
    if (strstr(*body, "&songID=")) {
        originalRequest(manager, url, body, tag, type);
        return;
    }
    const unsigned size = strlen(*body);
    if (size > 16 * 1024 * 1024) {
        __android_log_write(6, "MuchoCoreCocos", "Level upload is too large to attach a custom song");
        return;
    }
    auto* payload = static_cast<char*>(malloc(size + 24));
    if (!payload) return;
    memcpy(payload, *body, size);
    snprintf(payload + size, 24, "&songID=%d", uploadingSong);
    const char* altered = payload;
    originalRequest(manager, url, &altered, tag, type);
    free(payload);
}

bool installHooks(void* game) {
    Hook hooks[] = {
        {"_ZN11GJGameLevel15encodeWithCoderEP13DS_Dictionary", 0xfff3d75b, nullptr,
         reinterpret_cast<void*>(saveLevel), nullptr},
        {"_ZN11GJGameLevel10dataLoadedEP13DS_Dictionary", 0xfff31b3b, nullptr,
         reinterpret_cast<void*>(loadLevel), nullptr},
        {"_ZN16GameLevelManager11uploadLevelEP11GJGameLevel", 0xfff2aa3b, nullptr,
         reinterpret_cast<void*>(uploadLevel), nullptr},
        {"_ZN16GameLevelManager18ProcessHttpRequestESsSsSs10GJHttpType", 0xfff3cfbb, nullptr,
         reinterpret_cast<void*>(processRequest), nullptr},
    };
    auto* instructions = static_cast<unsigned char*>(mmap(
        nullptr, pageBytes, protectionRead | protectionWrite, privateAnon, -1, 0));
    if (instructions == reinterpret_cast<void*>(-1)) return false;
    for (unsigned i = 0; i < sizeof(hooks) / sizeof(hooks[0]); ++i) {
        if (!prepareHook(game, hooks[i], instructions + i * 16)) {
            __android_log_write(6, "MuchoCoreCocos", hooks[i].symbol);
            return false;
        }
    }
    if (mprotect(instructions, pageBytes, protectionRead | protectionExec)) return false;
    unsigned enabled = 0;
    for (Hook& hook : hooks) {
        if (mprotect(pageFor(hook.entry), pageBytes, protectionRead | protectionWrite | protectionExec)) {
            for (unsigned i = 0; i < enabled; ++i) {
                mprotect(pageFor(hooks[i].entry), pageBytes, protectionRead | protectionExec);
            }
            return false;
        }
        enabled++;
    }
    originalEncode = reinterpret_cast<decltype(originalEncode)>(hooks[0].trampoline);
    originalLoad = reinterpret_cast<decltype(originalLoad)>(hooks[1].trampoline);
    originalUpload = reinterpret_cast<decltype(originalUpload)>(hooks[2].trampoline);
    originalRequest = reinterpret_cast<decltype(originalRequest)>(hooks[3].trampoline);
    for (Hook& hook : hooks) jump(static_cast<unsigned char*>(hook.entry), hook.replacement);
    for (Hook& hook : hooks) mprotect(pageFor(hook.entry), pageBytes, protectionRead | protectionExec);
    return true;
}

JNIEnv* gameEnv() {
    JNIEnv* env = nullptr;
    if (vm) vm->GetEnv(reinterpret_cast<void**>(&env), JNI_VERSION_1_6);
    return env;
}

void closePane(Node* settings) {
    popupOwner = nullptr;
    api.remove(settings, paneTag, true);
    popupRevision = -1;
}

void rowPressed(Node* settings, Node* sender) {
    if (api.tag(sender) == closeTag) {
        closeRequested = settings;
        return;
    }
    if (JNIEnv* env = gameEnv()) {
        env->CallStaticVoidMethod(bridge, javaAction, api.tag(sender), popupRevision);
    }
}

Node* addLabel(Node* parent, int tag, const char* text, float x, float y, float size) {
    Node* label = api.label(text, "sans-serif", size);
    if (label) {
        api.position(label, x, y);
        api.add(parent, label, 1, tag);
    }
    return label;
}

void addButton(Node* menu, Node* owner, int tag, const char* text, float x, float y,
               void (*callback)(Node*, Node*), float fontSize = 13.0f) {
    Node* label = api.label(text, "sans-serif", fontSize);
    if (!label) return;
    Node* item = api.item(label, owner, {callback, 0});
    if (!item) return;
    api.position(item, x, y);
    api.add(menu, item, 0, tag);
}

void lookupButton(Node* menu, Node* settings, int tag, const char* label, float x, float y) {
    addButton(menu, settings, tag, label, x, y, rowPressed);
}

void drawLookup(JNIEnv* env, jobjectArray rows, Node* panel, Node* menu, Node* settings) {
    lookupButton(menu, settings, catalogTag, "Catalog", 48, 201);
    lookupButton(menu, settings, eraseTag, "Erase", 73, 53);
    lookupButton(menu, settings, searchTag, "Search", 255, 53);
    constexpr const char* digits[] = {"  0  ", "  1  ", "  2  ", "  3  ", "  4  ",
                                      "  5  ", "  6  ", "  7  ", "  8  ", "  9  "};
    for (int digit = 0; digit < 10; ++digit) {
        const int order = (digit + 9) % 10;
        addButton(menu, settings, firstDigitTag + digit, digits[digit], 58 + (order % 5) * 52,
                  order < 5 ? 113 : 82, rowPressed, 18);
    }
    for (int row = 0; row < 6; ++row) {
        jstring value = static_cast<jstring>(env->GetObjectArrayElement(rows, row));
        if (!value) continue;
        const char* label = env->GetStringUTFChars(value, nullptr);
        if (label && *label) {
            switch (row) {
                case 0: addLabel(panel, 0, label, 75, 172, 13); break;
                case 1: addLabel(panel, 0, label, 162, 143, 16); break;
                case 2: addLabel(panel, 0, label, 162, 53, 10); break;
                case 3: addLabel(panel, 0, label, 120, 20, 12); break;
                case 4: lookupButton(menu, settings, trackActionTag, label, 266, 20); break;
                case 5: lookupButton(menu, settings, switchSourceTag, label, 247, 172); break;
            }
        }
        if (label) env->ReleaseStringUTFChars(value, label);
        env->DeleteLocalRef(value);
    }
}

void buildPane(JNIEnv* env, Node* settings, const Size& size) {
    const int revision = env->CallStaticIntMethod(bridge, javaRevision);
    if (api.tagNode(settings, paneTag) && popupRevision == revision) return;
    popupRevision = revision;
    api.remove(settings, paneTag, true);
    const Color shade = {20, 24, 42, 252};
    const float width = 324, height = 220;
    Node* panel = api.shade(&shade, width, height);
    Node* menu = api.menu();
    if (!panel || !menu) return;
    api.position(panel, (size.width - width) / 2, (size.height - height) / 2);
    api.add(settings, panel, 900, paneTag);
    api.position(menu, 0, 0);
    api.add(panel, menu, 1, 0);
    api.priority(menu, songMenuTouchPriority);
    addLabel(panel, 0, "Custom Songs", width / 2, 201, 17);
    addButton(menu, settings, closeTag, "Close", 282, 201, rowPressed);
    jobjectArray rows = static_cast<jobjectArray>(env->CallStaticObjectMethod(bridge, javaRows));
    if (!rows) return;
    const int count = env->GetArrayLength(rows);
    if (count == 6) {
        drawLookup(env, rows, panel, menu, settings);
        env->DeleteLocalRef(rows);
        return;
    }
    addButton(menu, settings, findTag, "Find by ID", 48, 201, rowPressed);
    addButton(menu, settings, stockTag, "Use built-in", 82, 15, rowPressed);
    for (int i = 0; i < count; ++i) {
        jstring text = static_cast<jstring>(env->GetObjectArrayElement(rows, i));
        if (!text) continue;
        const char* utf = env->GetStringUTFChars(text, nullptr);
        if (utf && *utf) {
            if (i == 0) addLabel(panel, 0, utf, width / 2, 176, 11);
            else if (i == 6) addButton(menu, settings, moreTag, utf, 250, 15, rowPressed);
            else addButton(menu, settings, i - 1, utf, width / 2, 148 - (i - 1) * 24, rowPressed);
        }
        if (utf) env->ReleaseStringUTFChars(text, utf);
        env->DeleteLocalRef(text);
    }
    env->DeleteLocalRef(rows);
}

void showPane(Node* settings, Node*) {
    Node* level = editorLevel(settings);
    JNIEnv* env = gameEnv();
    if (!level || !env) return;
    popupOwner = settings;
    popupRevision = -1;
    env->CallStaticVoidMethod(bridge, javaOpen, api.objectId(level));
}

void setTitle(JNIEnv* env, Node* parent, Node* stockLabel, Node* level,
              int stock, float x, float y, bool remote) {
    const int song = boundSong(level, stock);
    const int levelId = remote && song == 0 ? api.levelId(level) : 0;
    jstring title = static_cast<jstring>(env->CallStaticObjectMethod(bridge, javaTitle, levelId, song));
    if (!title) return;
    const char* utf = env->GetStringUTFChars(title, nullptr);
    const bool custom = utf && *utf != 0;
    if (stockLabel) api.setVisible(stockLabel, !custom);
    Node* label = api.tagNode(parent, titleTag);
    const char* name = custom ? utf : (stockLabel ? "" : api.stockTitle(stock));
    if (name && *name) {
        if (label) {
            api.text(label, name);
            api.setVisible(label, true);
        } else {
            addLabel(parent, titleTag, name, x, y, 12);
        }
    } else if (label) {
        api.setVisible(label, false);
    }
    if (utf) env->ReleaseStringUTFChars(title, utf);
    env->DeleteLocalRef(title);
}

void levelTitle(JNIEnv* env, Node* layer, Node* level, int stock,
                float x, float y, bool remote) {
    Node* original = stockLabel(layer, api.stockTitle(stock));
    Node* parent = original ? api.parent(original) : layer;
    if (parent) {
        setTitle(env, parent, original, level, stock,
                 original ? api.x(original) : x, original ? api.y(original) : y, remote);
    }
}

void editorPanel(JNIEnv* env, Node* settings, Node* level, const Size& size) {
    Node* settingsObject = field(settings, settingsObjectOffset);
    Node* originalLabel = field(settings, settingsLabelOffset);
    if (!settingsObject || !originalLabel) return;
    const int stock = api.settingsTrack(settingsObject);
    const int objectId = api.objectId(level);
    for (Binding& binding : bindings) {
        if (binding.songId > 0 && binding.objectId == objectId && binding.stockSong != stock) {
            binding = {};
        }
    }
    if (closeRequested == settings) {
        env->CallStaticVoidMethod(bridge, javaCancel);
        closePane(settings);
    }
    closeRequested = nullptr;
    if (!api.tagNode(settings, tabTag)) {
        if (Node* menu = api.menu()) {
            api.position(menu, 0, 0);
            api.add(settings, menu, 200, tabTag);
            api.priority(menu, songMenuTouchPriority);
            addButton(menu, settings, tabTag, "Custom Songs", size.width - 72, size.height - 22, showPane);
        }
    }
    int picked = env->CallStaticIntMethod(bridge, javaSelection, api.objectId(level));
    if (picked >= 0) {
        bind(level, picked, stock);
        closePane(settings);
    }
    if (popupOwner == settings) buildPane(env, settings, size);
    Node* parent = api.parent(originalLabel);
    if (parent) {
        setTitle(env, parent, originalLabel, level, stock,
                 api.x(originalLabel), api.y(originalLabel), false);
    }
}

bool javaMethods(JNIEnv* env, jclass type) {
    if (env->GetJavaVM(&vm) != JNI_OK) return false;
    bridge = static_cast<jclass>(env->NewGlobalRef(type));
    javaOpen = env->GetStaticMethodID(type, "open", "(I)V");
    javaCancel = env->GetStaticMethodID(type, "cancel", "()V");
    javaAction = env->GetStaticMethodID(type, "action", "(II)V");
    javaRows = env->GetStaticMethodID(type, "rows", "()[Ljava/lang/String;");
    javaRevision = env->GetStaticMethodID(type, "revision", "()I");
    javaSelection = env->GetStaticMethodID(type, "selection", "(I)I");
    javaTitle = env->GetStaticMethodID(type, "title", "(II)Ljava/lang/String;");
    javaPath = env->GetStaticMethodID(type, "path", "(II)Ljava/lang/String;");
    return bridge && javaOpen && javaCancel && javaAction && javaRows && javaRevision &&
           javaSelection && javaTitle && javaPath;
}
}

extern "C" JNIEXPORT jboolean JNICALL
Java_space_muchogdps_legacysongs_CocosSongs_nativeStart(JNIEnv* env, jclass type) {
    if (!initialized) {
        initialized = true;
        ready = resolve() && javaMethods(env, type);
        if (!ready && env->ExceptionCheck()) env->ExceptionClear();
        __android_log_write(ready ? 4 : 6, "MuchoCoreCocos",
                            ready ? "Native song screens loaded" : "Native song screens unavailable");
    }
    return ready ? JNI_TRUE : JNI_FALSE;
}

extern "C" JNIEXPORT void JNICALL
Java_space_muchogdps_legacysongs_CocosSongs_nativeFrame(JNIEnv* env, jclass) {
    if (!ready || (++frames % 12) != 0) return;
    Node* scene = field(api.director(), runningSceneOffset);
    if (!scene) return;
    const Size& size = *api.size(scene);
    if (size.width < 330 || size.height < 230) return;

    Node* settings = find(scene, api.settingsType);
    if (popupOwner && settings != popupOwner) {
        env->CallStaticVoidMethod(bridge, javaCancel);
        popupOwner = nullptr;
    }
    if (settings) {
        if (Node* level = editorLevel(settings)) editorPanel(env, settings, level, size);
        return;
    }
    if (Node* info = find(scene, api.levelPageType)) {
        if (Node* level = field(info, levelInfoLevelOffset)) {
            levelTitle(env, info, level, api.audioTrack(level), size.width / 2, 46, true);
        }
    } else if (Node* info = find(scene, api.editLevelType)) {
        if (Node* level = field(info, editLevelOffset)) {
            levelTitle(env, info, level, api.audioTrack(level), size.width / 2, 38, false);
        }
    } else if (Node* pause = find(scene, api.editorPauseType)) {
        if (Node* editor = field(pause, editorPauseEditorOffset)) {
            if (Node* level = api.editorLevel(editor)) {
                if (Node* settings = api.editorSettings(editor)) {
                    levelTitle(env, pause, level, api.settingsTrack(settings), size.width / 2, 38, false);
                }
            }
        }
    } else if (Node* pause = find(scene, api.pauseType)) {
        if (Node* play = find(scene, api.playType)) {
            if (Node* level = api.playLevel(play)) {
                levelTitle(env, pause, level, api.audioTrack(level), size.width / 2, 28, true);
            }
        }
    }
}

extern "C" JNIEXPORT jstring JNICALL
Java_space_muchogdps_legacysongs_CocosSongs_nativeMusicPath(JNIEnv* env, jclass) {
    if (!ready) return nullptr;
    Node* scene = field(api.director(), runningSceneOffset);
    if (!scene) return nullptr;
    if (Node* play = find(scene, api.playType)) {
        if (Node* level = api.playLevel(play)) {
            const int song = boundSong(level, api.audioTrack(level));
            return static_cast<jstring>(env->CallStaticObjectMethod(bridge, javaPath, api.levelId(level), song));
        }
    }
    if (Node* editor = find(scene, api.editorType)) {
        Node* level = api.editorLevel(editor);
        Node* settings = api.editorSettings(editor);
        if (level && settings) {
            const int song = boundSong(level, api.settingsTrack(settings));
            return static_cast<jstring>(env->CallStaticObjectMethod(bridge, javaPath, 0, song));
        }
    }
    return nullptr;
}
