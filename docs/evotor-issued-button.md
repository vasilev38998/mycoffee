# Evotor issued button

For customer PWA orders, Evotor keeps ready orders active until the barista explicitly taps **ВЫДАН**. That action sends `complete`, transitions the order from `ready` to `completed`, and reuses the existing completion hooks for loyalty and order history.
