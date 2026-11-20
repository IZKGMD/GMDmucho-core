package protocol

import "testing"

func TestGD2209LevelDifficultyWire(t *testing.T) {
    tests := []struct {
        name string
        stored int64
        demon int64
        auto int64
        expected int64
    }{
        {"unrated", 0, 0, 0, 0},
        {"auto", 1, 0, 1, 0},
        {"easy", 2, 0, 0, 10},
        {"normal", 3, 0, 0, 20},
        {"hard", 4, 0, 0, 30},
        {"harder", 5, 0, 0, 40},
        {"insane", 6, 0, 0, 50},
        {"demon", 6, 1, 0, 50},
        {"legacy-protocol-insane", 50, 0, 0, 50},
    }
    for _, item := range tests {
        t.Run(item.name, func(t *testing.T) {
            actual := difficulty(item.stored, item.demon, item.auto)
            if actual != item.expected {
                t.Errorf("field 9: got %d want %d", actual, item.expected)
            }
        })
    }
}
