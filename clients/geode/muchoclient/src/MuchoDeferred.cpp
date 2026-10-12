#include "MuchoDeferred.hpp"
#include "MuchoPerformanceRules.hpp"
#include <Geode/Geode.hpp>
#include <Geode/binding/PlayLayer.hpp>
using namespace geode::prelude;
namespace mucho::performance {
namespace {
    class WorkPump final : public CCNode {
        DeferredWork m_work;
        bool m_scheduled = false;
        bool m_closed = false;
        void tick(float) {
            m_work.drain(PlayLayer::get() != nullptr);
            if (m_work.empty()) {
                CCDirector::get()->getScheduler()->unscheduleSelector(schedule_selector(WorkPump::tick),this);
                m_scheduled = false;
            }
        }
    public:
        bool add(std::function<bool()> valid, std::function<void()> run) {
            if (m_closed) return false;
            if (!m_work.add(std::move(valid),std::move(run))) return false;
            if (!m_scheduled) {
                m_scheduled = true;
                CCDirector::get()->getScheduler()->scheduleSelector(schedule_selector(WorkPump::tick),this,.05f,false);
            }
            return true;
        }
        void close() {
            m_closed = true; m_work.clear();
            if (m_scheduled) CCDirector::get()->getScheduler()->unscheduleSelector(schedule_selector(WorkPump::tick),this);
            m_scheduled = false;
        }
        static WorkPump* get() { static auto node = [] { auto n = new WorkPump; n->init(); return n; }(); return node; }
    };
}
    bool defer(std::function<bool()> valid,std::function<void()> run) { return WorkPump::get()->add(std::move(valid),std::move(run)); }
    bool cached(std::function<bool()> valid,std::function<void()> run) {
        if (!PlayLayer::get()) { if (valid()) run(); return true; }
        return defer(std::move(valid),std::move(run));
    }
    void shutdown() { WorkPump::get()->close(); }
}
