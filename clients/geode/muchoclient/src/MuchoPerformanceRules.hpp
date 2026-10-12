#pragma once
#include <algorithm>
#include <deque>
#include <functional>
#include <map>
#include <optional>
#include <utility>
#include <vector>

namespace mucho::performance {
    class DeferredWork {
        struct Work { std::function<bool()> valid; std::function<void()> run; };
        std::deque<Work> m_work;
    public:
        bool add(std::function<bool()> valid, std::function<void()> run) {
            std::erase_if(m_work, [](auto const& work) { return !work.valid(); });
            if (m_work.size() >= 256) return false;
            m_work.push_back({std::move(valid),std::move(run)}); return true;
        }
        void drain(bool gameplay, size_t budget = 2) {
            if (gameplay) return;
            size_t count = m_work.size();
            while (count-- && !m_work.empty() && budget) {
                auto work = std::move(m_work.front()); m_work.pop_front();
                if (work.valid()) { --budget; work.run(); }
            }
        }
        bool empty() const { return m_work.empty(); }
    };

    // One request per key, a fixed number of active jobs and weak subscribers.
    template<class Key, class Params, class Result> class SharedRequests {
        struct Subscriber { std::function<bool()> valid; std::function<void(Result const&)> done; };
        struct Job { Params params; bool active = false; std::vector<Subscriber> subscribers; };
        std::map<Key, Job> m_jobs;
        size_t m_active = 0;
    public:
        bool add(Key key, Params params, std::function<bool()> valid, std::function<void(Result const&)> done) {
            auto found = m_jobs.find(key);
            if (found == m_jobs.end()) {
                if (m_jobs.size() >= 128) return false;
                found = m_jobs.emplace(std::move(key),Job{std::move(params),false,{}}).first;
            }
            auto& subscribers = found->second.subscribers;
            std::erase_if(subscribers, [](auto const& subscriber) { return !subscriber.valid(); });
            if (subscribers.size() >= 64) return false;
            subscribers.push_back({std::move(valid),std::move(done)}); return true;
        }
        std::optional<std::pair<Key,Params>> take() {
            if (m_active >= 2) return {};
            for (auto it = m_jobs.begin(); it != m_jobs.end();) {
                auto& job = it->second;
                if (job.active) { ++it; continue; }
                std::erase_if(job.subscribers, [](auto const& subscriber) { return !subscriber.valid(); });
                if (job.subscribers.empty()) { it = m_jobs.erase(it); continue; }
                job.active = true; ++m_active;
                return std::pair{it->first,job.params};
            }
            return {};
        }
        bool live(Key const& key) const {
            auto found = m_jobs.find(key);
            return found != m_jobs.end() && std::any_of(found->second.subscribers.begin(),found->second.subscribers.end(),
                [](auto const& subscriber) { return subscriber.valid(); });
        }
        void complete(Key const& key, Result result) {
            auto found = m_jobs.find(key); if (found == m_jobs.end()) return;
            auto job = std::move(found->second); m_jobs.erase(found);
            if (job.active) --m_active;
            for (auto const& subscriber : job.subscribers) if (subscriber.valid()) subscriber.done(result);
        }
        void discard(Key const& key) {
            auto found = m_jobs.find(key); if (found == m_jobs.end()) return;
            if (found->second.active) --m_active;
            m_jobs.erase(found);
        }
        size_t active() const { return m_active; }
        size_t size() const { return m_jobs.size(); }
    };

    template<class Key, class Value> class WeightedCache {
        struct Entry { Value value; size_t weight; unsigned long long used; };
        std::map<Key,Entry> m_entries;
        size_t m_weight = 0, m_limit, m_count;
        unsigned long long m_serial = 0;
    public:
        WeightedCache(size_t limit, size_t count): m_limit(limit),m_count(count) {}
        std::optional<Value> get(Key const& key) {
            auto found = m_entries.find(key); if (found == m_entries.end()) return {};
            found->second.used = ++m_serial; return found->second.value;
        }
        void put(Key key, Value value, size_t weight) {
            if (weight > m_limit || !m_count) return;
            if (auto found = m_entries.find(key); found != m_entries.end()) {
                m_weight -= found->second.weight; m_entries.erase(found);
            }
            while (!m_entries.empty() && (m_weight + weight > m_limit || m_entries.size() >= m_count)) {
                auto oldest = std::min_element(m_entries.begin(),m_entries.end(),[](auto const& a,auto const& b) { return a.second.used < b.second.used; });
                m_weight -= oldest->second.weight; m_entries.erase(oldest);
            }
            m_weight += weight; m_entries.emplace(std::move(key),Entry{std::move(value),weight,++m_serial});
        }
        void clear() { m_entries.clear(); m_weight = 0; }
        size_t weight() const { return m_weight; }
        size_t size() const { return m_entries.size(); }
    };
    inline bool repaintNeeded(unsigned long long painted, unsigned long long revision) { return painted != revision; }
}
