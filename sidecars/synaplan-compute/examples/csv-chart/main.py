"""CSV → PNG demo for T1 (pandas + matplotlib). Writes /out/chart.png."""
import pandas as pd
import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt

df = pd.read_csv("data.csv")
print(df.describe())
ax = df.plot(x="year", y="value", kind="bar", legend=False)
ax.set_title("values")
fig = ax.get_figure()
fig.savefig("/out/chart.png")
