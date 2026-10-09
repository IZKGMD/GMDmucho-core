#include <Geode/Geode.hpp>
#include <Geode/modify/MenuLayer.hpp>
#include <Geode/utils/web.hpp>
#include <Geode/utils/async.hpp>

#include <chrono>
#include <cstdio>
#include <string>
#include <tuple>

using namespace geode::prelude;

namespace {
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
        return origin.substr(8).find('/') == std::string::npos;
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
        return std::tuple{0u, 1u, 0u} >= std::tuple{major, minor, patch};
    }

    std::string displaySafe(std::string text) {
        // Escape server-provided rich-text markup before FLAlertLayer display.
        for (auto& c : text) {
            if (c == '<') c = '[';
            if (c == '>') c = ']';
        }
        if (text.size() > 90) text.resize(90);
        return text;
    }
}

class $modify(MuchoMenuLayer, MenuLayer) {
    struct Fields {
        async::TaskHolder<web::WebResponse> m_manifestTask;
    };

    bool init() {
        if (!MenuLayer::init()) {
            return false;
        }

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
        while (!origin.empty() && origin.back() == '/') {
            origin.pop_back();
        }

        if (!validOrigin(origin)) {
            FLAlertLayer::create(
                "MuchoClient",
                "Enter your GDPS HTTPS URL in MuchoClient settings first.",
                "OK"
            )->show();
            return;
        }

        m_fields->m_manifestTask.spawn(
            "Fetch MuchoClient feature catalog",
            web::WebRequest()
                .timeout(std::chrono::seconds(8))
                .followRedirects(false)
                .get(origin + "/muchoclient/manifest"),
            [](web::WebResponse response) {
                if (!response.ok()) {
                    FLAlertLayer::create(
                        "MuchoClient",
                        "Cannot contact your MuchoCore server.",
                        "OK"
                    )->show();
                    return;
                }

                auto parsed = response.json();
                if (!parsed) {
                    FLAlertLayer::create(
                        "MuchoClient", "Invalid feature catalog.", "OK"
                    )->show();
                    return;
                }

                auto json = parsed.unwrap();
                auto client = json["client"];
                if (
                    client["id"].asString().unwrapOr("") != "izkgmd.muchoclient" ||
                    client["protocol"].asInt().unwrapOr(0) != 1
                ) {
                    FLAlertLayer::create(
                        "MuchoClient",
                        "This server requires a different MuchoClient version.",
                        "OK"
                    )->show();
                    return;
                }

                auto minimum = client["min_version"].asString().unwrapOr("");
                if (!compatibleClientVersion(minimum)) {
                    FLAlertLayer::create(
                        "MuchoClient",
                        "Please update MuchoClient to access this GDPS content.",
                        "OK"
                    )->show();
                    return;
                }

                auto features = json["features"];
                if (!features.isArray()) {
                    FLAlertLayer::create(
                        "MuchoClient", "No feature list in server response.", "OK"
                    )->show();
                    return;
                }

                std::string lines = "Server extension modules:\n";
                size_t count = 0;
                for (auto const& item : features) {
                    if (count >= 6) break;

                    auto name = displaySafe(
                        item["name"].asString().unwrapOr("Unknown")
                    );
                    auto description = displaySafe(
                        item["description"].asString().unwrapOr("")
                    );
                    lines += "\n" + name + ": " + description;
                    ++count;
                }
                if (features.size() > count) lines += "\nMore modules available";
                if (count == 0) lines += "\nNo modules yet";

                FLAlertLayer::create(
                    "MuchoClient", lines.c_str(), "OK"
                )->show();
            }
        );
    }
};
