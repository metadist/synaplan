FROM golang:1.27-bookworm@sha256:648f440f42a0958804efb24df176f806f9d353b41f1c0627f666428e40310f6b AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -o /synaplan-compute ./cmd/synaplan-compute

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /synaplan-compute /synaplan-compute
USER nonroot:nonroot
ENTRYPOINT ["/synaplan-compute"]
