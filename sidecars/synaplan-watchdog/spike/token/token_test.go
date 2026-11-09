package token

import "testing"

const (
	secret = "test-secret"
	vector = "cnVuLTEKdXNlci03CjAKMTcwMDAwMDAwMA.5_VeI1_xcbothpgKhr1hw-QlKQGRK3YvY2k30tyu1Mc"
)

func TestSignMatchesThePHPVector(t *testing.T) {
	got := Sign(secret, "run-1", "user-7", 0, 1700000000)
	if got != vector {
		t.Fatalf("sign = %s", got)
	}
}

func TestVerifyRejectsAWidenedCursorAndAnExpiredToken(t *testing.T) {
	if err := Verify(secret, vector, "run-1", "user-7", 0, 1700000000); err != nil {
		t.Fatal(err)
	}
	if err := Verify(secret, vector, "run-1", "user-7", 1, 1700000000); err == nil {
		t.Fatal("widened cursor was accepted")
	}
	if err := Verify(secret, vector, "run-1", "user-7", 0, 1700000001); err == nil {
		t.Fatal("expired token was accepted")
	}
	if err := Verify("other-secret", vector, "run-1", "user-7", 0, 1700000000); err == nil {
		t.Fatal("other secret was accepted")
	}
}
