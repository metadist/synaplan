package token

import "testing"

func TestVerifyRejectsAWidenedCursor(t *testing.T) {
	token := Sign("test-secret", "run-1", "user:7", 4, 2_000_000_000)
	if err := Verify("test-secret", token, "run-1", "user:7", 4, 1_900_000_000); err != nil {
		t.Fatal(err)
	}
	if err := Verify("test-secret", token, "run-1", "user:7", 0, 1_900_000_000); err == nil {
		t.Fatal("a widened cursor was accepted")
	}
	if err := Verify("test-secret", token, "run-1", "user:7", 4, 2_000_000_001); err == nil {
		t.Fatal("an expired token was accepted")
	}
	runID, owner, seq, _, err := Peek(token)
	if err != nil || runID != "run-1" || owner != "user:7" || seq != 4 {
		t.Fatalf("peek = %s %s %d %v", runID, owner, seq, err)
	}
}
