#include <Geode/Geode.hpp>
#include <Geode/modify/MenuLayer.hpp>
#include <Geode/ui/Popup.hpp>
#include <Geode/utils/web.hpp>
#include <Geode/utils/async.hpp>

#include <algorithm>
#include <chrono>
#include <cstdio>
#include <string>
#include <tuple>
#include <vector>

using namespace geode::prelude;

namespace {
    constexpr char const* CLIENT_ID = "izkgmd.muchoclient";
    constexpr char const* CLIENT_VERSION = "0.1.0";
    constexpr int PROTOCOL = 1;

    struct Feature {
        std::string id;
        std::string name;
        std::string description;
        std::string entrypoint;
    };

    bool validOrigin(std::string const& origin) {
        if (!origin.starts_with("https://") || origin.size() < 12) {
            return false;
        }
        if (origin.find('@') != std::string::npos ||
            origin.find('#') != std::string::npos ||
            origin.find('?') != std::string::npos ||
            origin.find('\\') != std::string::npos) {
            return false;
        }
        auto host = origin.substr(8);
        if (host.find('/') != std::string::npos || host.empty()) return false;
        for (char c : host) {
            if (!((c >= 'a' && c <= 'z') ||
                  (c >= 'A' && c <= 'Z') ||
                  (c >= '0' && c <= '9') ||
                  c == '.' || c == '-' || c == ':')) {
                return false;
            }
        }
        return true;
    }

    bool compatibleClientVersion(std::string const& required) {
        unsigned major = 0, minor = 0, patch = 0;
        char trailing = '\0';
        if (std::sscanf(
            required.c_str(), "%u.%u.%u%c",
            &major, &minor, &patch, &trailing
        ) != 3) {
            return false;
        }
        return std::tuple{0u, 2u, 0u} >= std::tuple{major, minor, patch};
    }

    // Untrusted server text is displayed only, never evaluated as UI markup.
    std::string displaySafe(std::string text, size_t limit = 120) {
        for (auto& c : text) {
            if (c == '<' || c == '>') c = ' ';
            if (static_cast<unsigned char>(c) < 32 &&
                c != '\n' && c != '\t') c = ' ';
            if (c == '\n' || c == '\t') c = ' ';
        }
        if (text.size() > limit) text.resize(limit);
        return text;
    }

    bool safeFeaturePath(std::string const& path) {
        if (!path.starts_with("/extensions/") ||
            path.size() > 132 || path.size() <= 12 ||
            path.find("//") != std::string::npos) {
            return false;
        }
        for (char c : path.substr(12)) {
            if (!((c >= 'a' && c <= 'z') ||
                  (c >= '0' && c <= '9') ||
                  c == '/' || c == '-' || c == '_')) {
                return false;
            }
        }
        return true;
    }

    void showInfo(std::string const& title, std::string const& detail) {
        FLAlertLayer::create(
            displaySafe(title, 55).c_str(),
            displaySafe(detail, 650).c_str(),
            "OK"
        )->show();
    }
}

// All functionality in this popup is data-driven. Third-party PHP code runs
// only on MuchoCore, never inside the game. Only JSON text is displayed.
class MuchoFeaturesPopup : public geode::Popup {
    std::string m_origin;
    std::vector<Feature> m_features;
    async::TaskHolder<web::WebResponse> m_featureTask;

protected:
    bool init(std::string origin, std::vector<Feature> features) {
        if (!Popup::init(340.f, 270.f)) return false;
        m_origin = std::move(origin);
        m_features = std::move(features);
        this->setTitle("MuchoClient | Modules");

        auto status = CCLabelBMFont::create(
            "Connected to MuchoCore", "goldFont.fnt"
        );
        status->setScale(.4f);
        status->setPosition({170.f, 221.f});
        m_mainLayer->addChild(status);

        auto menu = CCMenu::create();
        menu->setPosition({170.f, 121.f});
        size_t count = std::min(m_features.size(), size_t{5});
        for (size_t i = 0; i < count; ++i) {
            auto text = displaySafe(m_features[i].name, 22);
            auto button = CCMenuItemSpriteExtra::create(
                ButtonSprite::create(text.c_str()),
                this,
                menu_selector(MuchoFeaturesPopup::onFeature)
            );
            button->setScale(.67f);
            button->setTag(static_cast<int>(i));
            button->setPosition({0.f, 76.f - static_cast<float>(i) * 37.f});
            menu->addChild(button);
        }
        m_mainLayer->addChild(menu);

        if (m_features.empty()) {
            auto label = CCLabelBMFont::create(
                "No modules are enabled yet", "bigFont.fnt"
            );
            label->setScale(.45f);
            label->setPosition({170.f, 120.f});
            m_mainLayer->addChild(label);
        } else if (m_features.size() > count) {
            auto label = CCLabelBMFont::create(
                "Showing the first 5 modules", "bigFont.fnt"
            );
            label->setScale(.35f);
            label->setPosition({170.f, 25.f});
            m_mainLayer->addChild(label);
        }
        return true;
    }

    void onFeature(CCObject* sender) {
        auto index = static_cast<CCMenuItemSpriteExtra*>(sender)->getTag();
        if (index < 0 || static_cast<size_t>(index) >= m_features.size()) return;
        auto feature = m_features[static_cast<size_t>(index)];

        // Core Clans is authenticated; this proof-of-concept never pretends
        // that an unauthenticated public call grants access to account data.
        if (!safeFeaturePath(feature.entrypoint)) {
            showInfo(feature.name, "This module needs in-game account integration.");
            return;
        }

        m_featureTask.spawn(
            "Load MuchoCore extension",
            web::WebRequest()
                .timeout(std::chrono::seconds(8))
                .followRedirects(false)
                .get(m_origin + feature.entrypoint),
            [feature](web::WebResponse response) {
                if (!response.ok()) {
                    showInfo(feature.name, "The server module is unavailable.");
                    return;
                }
                auto raw = response.string().unwrapOr("");
                if (raw.size() > 8192) {
                    showInfo(feature.name, "Module response was too large.");
                    return;
                }
                auto parsed = response.json();
                if (!parsed) {
                    showInfo(feature.name, "Server returned an invalid module response.");
                    return;
                }
                auto data = parsed.unwrap();
                if (data["schema_version"].asInt().unwrapOr(0) != 1 ||
                    data["feature_id"].asString().unwrapOr("") != feature.id) {
                    showInfo(feature.name, "Unsupported module protocol.");
                    return;
                }
                showInfo(
                    data["title"].asString().unwrapOr(feature.name),
                    data["message"].asString().unwrapOr("No module content.")
                );
            }
        );
    }

public:
    static MuchoFeaturesPopup* create(
        std::string origin, std::vector<Feature> features
    ) {
        auto ret = new MuchoFeaturesPopup();
        if (ret->init(std::move(origin), std::move(features))) {
            ret->autorelease();
            return ret;
        }
        delete ret;
        return nullptr;
    }
};

class $modify(MuchoMenuLayer, MenuLayer) {
    struct Fields {
        async::TaskHolder<web::WebResponse> m_manifestTask;
        async::TaskHolder<web::WebResponse> m_negotiateTask;
    };

    bool init() {
        if (!MenuLayer::init()) return false;
        auto button = CCMenuItemSpriteExtra::create(
            ButtonSprite::create("Mucho"),
            this,
            menu_selector(MuchoMenuLayer::onMucho)
        );
        auto menu = CCMenu::create();
        menu->addChild(button);
        auto size = CCDirector::sharedDirector()->getWinSize();
        menu->setPosition({size.width - 50.f, size.height - 42.f});
        this->addChild(menu, 20);
        return true;
    }

    void onMucho(CCObject*) {
        auto origin = Mod::get()->getSettingValue<std::string>("server-url");
        while (!origin.empty() && origin.back() == '/') origin.pop_back();

        if (!validOrigin(origin)) {
            showInfo(
                "MuchoClient",
                "Enter your GDPS HTTPS URL in MuchoClient settings first."
            );
            return;
        }

        m_fields->m_manifestTask.spawn(
            "Fetch MuchoCore modules",
            web::WebRequest()
                .timeout(std::chrono::seconds(8))
                .followRedirects(false)
                .get(origin + "/muchoclient/manifest"),
            [this, origin](web::WebResponse response) {
                if (!response.ok()) {
                    showInfo("MuchoClient", "Cannot contact your MuchoCore server.");
                    return;
                }
                auto raw = response.string().unwrapOr("");
                if (raw.size() > 32768) {
                    showInfo("MuchoClient", "Module catalog is too large.");
                    return;
                }
                auto parsed = response.json();
                if (!parsed) {
                    showInfo(
                        "MuchoClient",
                        "Invalid server catalog. Check /muchoclient/manifest."
                    );
                    return;
                }

                auto json = parsed.unwrap();
                auto client = json["client"];
                if (client["id"].asString().unwrapOr("") != CLIENT_ID ||
                    client["protocol"].asInt().unwrapOr(0) != PROTOCOL) {
                    showInfo(
                        "MuchoClient",
                        "This server requires a different MuchoClient protocol."
                    );
                    return;
                }
                auto minimum = client["min_version"].asString().unwrapOr("");
                if (!compatibleClientVersion(minimum)) {
                    showInfo(
                        "MuchoClient",
                        "Please update MuchoClient to access these modules."
                    );
                    return;
                }

                auto data = json["features"];
                if (!data.isArray()) {
                    showInfo("MuchoClient", "No feature list in the server response.");
                    return;
                }

                std::vector<Feature> features;
                for (auto const& item : data) {
                    if (features.size() >= 50) break;
                    auto id = item["id"].asString().unwrapOr("");
                    auto name = item["name"].asString().unwrapOr("");
                    auto path = item["entrypoint"].asString().unwrapOr("");
                    if (id.empty() || id.size() > 64 ||
                        name.empty() || name.size() > 64 ||
                        !(safeFeaturePath(path) || path == "/api/clans/my")) {
                        continue;
                    }
                    features.push_back({
                        id,
                        displaySafe(name, 64),
                        displaySafe(item["description"].asString().unwrapOr(""), 160),
                        path
                    });
                }

                // This is a protocol handshake, NOT user authentication.
                m_fields->m_negotiateTask.spawn(
                    "Negotiate MuchoClient protocol",
                    web::WebRequest()
                        .timeout(std::chrono::seconds(8))
                        .followRedirects(false)
                        .header("Content-Type", "application/x-www-form-urlencoded")
                        .bodyString(
                            std::string("client_version=") + CLIENT_VERSION +
                            "&protocol=" + std::to_string(PROTOCOL)
                        )
                        .post(origin + "/muchoclient/negotiate"),
                    [origin, features = std::move(features)](web::WebResponse reply) {
                        if (!reply.ok()) {
                            showInfo("MuchoClient", "Cannot negotiate with MuchoCore.");
                            return;
                        }
                        auto parsed = reply.json();
                        if (!parsed) {
                            showInfo("MuchoClient", "Invalid negotiation response.");
                            return;
                        }
                        auto data = parsed.unwrap();
                        if (!data["compatible"].asBool().unwrapOr(false) ||
                            data["protocol"].asInt().unwrapOr(0) != PROTOCOL) {
                            showInfo(
                                "MuchoClient",
                                "Server rejected the client version or protocol."
                            );
                            return;
                        }
                        if (auto popup = MuchoFeaturesPopup::create(origin, features)) {
                            popup->show();
                        }
                    }
                );
            }
        );
    }
};
