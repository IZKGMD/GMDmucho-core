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
    constexpr char CLIENT_VERSION[] = "0.1.1";
    constexpr int CLIENT_PROTOCOL = 1;

    bool validOrigin(std::string const& origin) {
        if (!origin.starts_with("https://") || origin.size() < 12 ||
            origin.size() > 255) {
            return false;
        }
        if (origin.find('@') != std::string::npos ||
            origin.find('#') != std::string::npos ||
            origin.find('?') != std::string::npos ||
            origin.find('\\') != std::string::npos ||
            origin.find(' ') != std::string::npos) {
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
        return std::tuple{0u, 1u, 1u} >= std::tuple{major, minor, patch};
    }

    std::string displaySafe(std::string text, size_t maxLength = 75) {
        // Avoid server-provided rich-text markup inside FLAlertLayer.
        for (auto& c : text) {
            if (c == '<') c = '[';
            if (c == '>') c = ']';
        }
        if (text.size() > maxLength) text.resize(maxLength);
        return text;
    }

    void showClientError(char const* message) {
        FLAlertLayer::create("MuchoClient", message, "OK")->show();
    }

    bool isLegacyFailure(web::WebResponse const& response) {
        return response.string().unwrapOr("") == "-1";
    }
}

class $modify(MuchoMenuLayer, MenuLayer) {
    struct Fields {
        async::TaskHolder<web::WebResponse> m_negotiateTask;
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
            showClientError(
                "Set a valid HTTPS GDPS URL in MuchoClient settings."
            );
            return;
        }

        // Negotiate BEFORE showing server-provided extension features.
        // This establishes protocol compatibility, not player authentication.
        m_fields->m_negotiateTask.spawn(
            "Negotiate MuchoClient protocol",
            web::WebRequest()
                .timeout(std::chrono::seconds(8))
                .followRedirects(false)
                .header("Content-Type", "application/x-www-form-urlencoded")
                .bodyString("client_version=0.1.1&protocol=1")
                .post(origin + "/muchoclient/negotiate"),
            [this, origin](web::WebResponse response) {
                if (!response.ok()) {
                    showClientError(
                        "Server handshake failed. Check your GDPS URL and server."
                    );
                    return;
                }
                if (isLegacyFailure(response)) {
                    showClientError(
                        "MuchoCore returned -1 during handshake. Update or repair the server."
                    );
                    return;
                }

                auto parsed = response.json();
                if (!parsed) {
                    showClientError(
                        "Invalid handshake response from MuchoCore."
                    );
                    return;
                }

                auto json = parsed.unwrap();
                if (
                    json["compatible"].asBool().unwrapOr(false) != true ||
                    json["protocol"].asInt().unwrapOr(0) != CLIENT_PROTOCOL ||
                    json["status"].asString().unwrapOr("") != "ready"
                ) {
                    showClientError(
                        "MuchoCore and MuchoClient are incompatible. Update the client or server."
                    );
                    return;
                }
                this->requestManifest(origin);
            }
        );
    }

    void requestManifest(std::string const& origin) {
        m_fields->m_manifestTask.spawn(
            "Fetch MuchoClient feature catalog",
            web::WebRequest()
                .timeout(std::chrono::seconds(8))
                .followRedirects(false)
                .get(origin + "/muchoclient/manifest"),
            [](web::WebResponse response) {
                if (!response.ok()) {
                    showClientError("Cannot fetch MuchoCore extension catalog.");
                    return;
                }
                if (isLegacyFailure(response)) {
                    showClientError(
                        "MuchoCore returned -1 for the feature catalog. Check the server."
                    );
                    return;
                }

                auto parsed = response.json();
                if (!parsed) {
                    showClientError("Invalid feature catalog from MuchoCore.");
                    return;
                }

                auto json = parsed.unwrap();
                auto client = json["client"];
                if (
                    client["id"].asString().unwrapOr("") != "izkgmd.muchoclient" ||
                    client["protocol"].asInt().unwrapOr(0) != CLIENT_PROTOCOL
                ) {
                    showClientError("This server requires a different MuchoClient version.");
                    return;
                }

                auto minimum = client["min_version"].asString().unwrapOr("");
                if (!compatibleClientVersion(minimum)) {
                    showClientError("Please update MuchoClient to access this content.");
                    return;
                }

                auto features = json["features"];
                if (!features.isArray()) {
                    showClientError("No feature list in MuchoCore response.");
                    return;
                }

                std::string lines = "Server extension modules:\n";
                size_t count = 0;
                for (auto const& item : features) {
                    if (count >= 6) break;
                    auto name = displaySafe(
                        item["name"].asString().unwrapOr("Unknown"), 50
                    );
                    auto description = displaySafe(
                        item["description"].asString().unwrapOr(""), 75
                    );
                    lines += "\n" + name + ": " + description;
                    ++count;
                }
                if (features.size() > count) lines += "\nMore modules available";
                if (count == 0) lines += "\nNo modules yet";

                FLAlertLayer::create("MuchoClient", lines.c_str(), "OK")->show();
            }
        );
    }
};
