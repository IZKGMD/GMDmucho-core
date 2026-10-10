#pragma once
#include <Geode/Geode.hpp>
#include <Geode/ui/LazySprite.hpp>
#include <Geode/utils/async.hpp>
#include <Geode/utils/web.hpp>
#include "MuchoAppearance.hpp"
#include "MuchoProfileRules.hpp"
#include <chrono>
#include <cmath>
#include <functional>

using namespace geode::prelude;

namespace mucho::profile {
    inline std::string origin() {
        auto url = Mod::get()->getSettingValue<std::string>("server-url");
        while (!url.empty() && url.back() == '/') url.pop_back();
        return url;
    }
    void clearCache();
    std::optional<std::vector<uint8_t>> cached(std::string const& key);
    void cache(std::string key, std::vector<uint8_t> bytes);

    class Artwork final : public CCNode {
        LazySprite* m_image = nullptr;
        CCScale9Sprite* m_background = nullptr;
        CCLabelBMFont* m_hint = nullptr;
        CCSize m_box;
        std::string m_origin;
        unsigned m_generation = 0;
        bool m_ready = false;
        int m_x = 500, m_y = 500, m_zoom = 100, m_rotation = 0;
        GLubyte m_dim = 255;
        async::TaskHolder<web::WebResponse> m_task;

        void reset() {
            ++m_generation; m_task.cancel(); m_ready = false;
            if (m_image) m_image->removeFromParent();
            m_image = LazySprite::create(m_box, false);
            m_image->setOpacity(m_dim);
            m_image->setVisible(false); addChild(m_image, 1);
            m_hint->setVisible(true);
        }
        void decode(std::vector<uint8_t> bytes, CCImage::EImageFormat format) {
            auto weak = WeakRef(this); auto generation = m_generation; auto image = m_image;
            // Keep the decoder alive until completion, even when this view closes.
            // Cancelling LazySprite mid-decode in Geode 5.10 can abandon its CCImage.
            image->retain();
            image->setLoadCallback([weak, generation, image](Result<> result) {
                geode::queueInMainThread([image] { image->release(); });
                auto self = weak.lock();
                if (!self || generation != self->m_generation) return;
                if (!result) { self->m_hint->setString("Image unavailable"); return; }
                self->m_ready = true; self->m_hint->setVisible(false); self->fit();
            });
            image->loadFromData(std::move(bytes),format);
        }
        void fit() {
            if (!m_ready) return;
            auto size = m_image->getTexture()->getContentSize();
            float w = size.width, h = size.height;
            bool quarter = m_rotation == 90 || m_rotation == 270;
            float rw = quarter ? h : w, rh = quarter ? w : h;
            float scale = std::max(m_box.width / rw, m_box.height / rh) * (m_zoom / 100.f);
            float cw = std::min(rw, std::round(m_box.width / scale));
            float ch = std::min(rh, std::round(m_box.height / scale));
            float left = std::round((rw - cw) * (m_x / 1000.f));
            float top = std::round((rh - ch) * (m_y / 1000.f));
            CCRect rect{left, top, cw, ch};
            if (m_rotation == 90) rect = {top, h - left - cw, ch, cw};
            if (m_rotation == 180) rect = {w - left - cw, h - top - ch, cw, ch};
            if (m_rotation == 270) rect = {w - top - ch, left, ch, cw};
            m_image->setTextureRect(rect); m_image->setRotation(static_cast<float>(m_rotation));
            m_image->setScale(scale); m_image->setPosition(m_box / 2);
            m_image->setOpacity(m_dim);
            m_image->setVisible(true);
        }
    public:
        bool ready() const { return m_ready; }
        bool loading() const { return m_image && m_image->isLoading(); }
        void dim(GLubyte opacity) { m_dim = opacity; if (m_image) m_image->setOpacity(opacity); }
        void crop(int x, int y, int zoom, int rotation) {
            m_x = x; m_y = y; m_zoom = zoom; m_rotation = rotation; fit();
        }
        void empty() {
            reset(); m_hint->setString(m_box.width == m_box.height ? "Avatar" : "Cover");
        }
        void selected(std::vector<uint8_t> const& bytes, bool png) {
            reset(); m_x = m_y = 500; m_zoom = 100; m_rotation = 0;
            decode(bytes, png ? CCImage::kFmtPng : CCImage::kFmtJpg);
        }
        void stored(std::string url, int account, std::string kind, std::string version) {
            empty(); m_origin = std::move(url); m_x = m_y = 500; m_zoom = 100; m_rotation = 0;
            if (!appearance::validOrigin(m_origin) || !validVersion(version) || account <= 0 || (kind != "avatar" && kind != "banner")) return;
            std::string key = m_origin + "/" + std::to_string(account) + "/" + kind + "/" + version;
            if (auto bytes = cached(key)) { decode(std::move(*bytes), CCImage::kFmtJpg); return; }
            auto generation = m_generation;
            m_task.spawn("MuchoCore profile image", web::WebRequest().timeout(std::chrono::seconds(12)).followRedirects(false)
                .header("Content-Type", "application/x-www-form-urlencoded")
                .bodyString("accountID=" + std::to_string(account) + "&kind=" + kind + "&version=" + version)
                .post(m_origin + "/api/profile/image"), [this, key, kind, generation](web::WebResponse response) {
                    if (generation != m_generation || origin() != m_origin) return;
                    auto const& bytes = response.data(); auto size = imageSize(bytes);
                    if (!response.ok() || bytes.size() > MAX_IMAGE_BYTES || !size || size->png ||
                        size->width != (kind == "avatar" ? 256u : 960u) || size->height != (kind == "avatar" ? 256u : 320u)) {
                        m_hint->setString("Image unavailable"); return;
                    }
                    cache(key, bytes); decode(bytes, CCImage::kFmtJpg);
                });
        }
        static Artwork* create(CCSize size) {
            auto node = new Artwork();
            if (!node->init()) { delete node; return nullptr; }
            node->m_box = size; node->setContentSize(size);
            node->m_background = CCScale9Sprite::create("square02_small.png");
            node->m_background->setContentSize(size); node->m_background->setPosition(size / 2);
            node->m_background->setColor({27,41,65}); node->addChild(node->m_background);
            node->m_hint = CCLabelBMFont::create(size.width == size.height ? "Avatar" : "Cover", "bigFont.fnt");
            node->m_hint->limitLabelWidth(size.width - 8, .3f, 0.f); node->m_hint->setPosition(size / 2);
            node->m_hint->setOpacity(140); node->addChild(node->m_hint, 2);
            node->empty(); node->autorelease(); return node;
        }
    };
}
