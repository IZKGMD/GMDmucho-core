This service is MuchoCore code. It runs the quantized ONNX conversion of
Falconsai/nsfw_image_detection published by onnx-community, pinned in model.json.

Original model: https://huggingface.co/Falconsai/nsfw_image_detection
Conversion: https://huggingface.co/onnx-community/nsfw_image_detection-ONNX
The original model card specifies Apache-2.0:
https://www.apache.org/licenses/LICENSE-2.0

Download happens only at build/setup. Inference uses local weights, CPU ONNX
Runtime and Pillow; it never sends uploads to a third party. The model has
normal/nsfw labels. It does not identify all prohibited material, age, legal
status, violence or CSAM. Reports and server hash blocks require human review.
