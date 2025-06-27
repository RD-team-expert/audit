<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Upload } from 'lucide-vue-next';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'Audit Upload',
        href: '/audit-upload',
    },
];

const fileInput = ref<HTMLInputElement | null>(null);
const selectedFile = ref<File | null>(null);
const uploadStatus = ref<string>('');
const isUploading = ref(false);

const form = useForm({
    pdf: null as File | null,
});

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    
    if (file) {
        if (file.type !== 'application/pdf') {
            uploadStatus.value = 'Please select a PDF file.';
            return;
        }
        
        if (file.size > 10 * 1024 * 1024) { // 10MB limit
            uploadStatus.value = 'File size must be less than 10MB.';
            return;
        }
        
        selectedFile.value = file;
        form.pdf = file;
        uploadStatus.value = '';
    }
};

const uploadFile = async () => {
    if (!selectedFile.value) {
        uploadStatus.value = 'Please select a file first';
        return;
    }

    const formData = new FormData();
    formData.append('file', selectedFile.value);

    try {
        isUploading.value = true;
        uploadStatus.value = '';
        
        const response = await fetch('/api/upload-audit', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Accept': 'application/json',
            },
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
        }

        const result = await response.json();
        uploadStatus.value = result.message || 'Upload successful!';
        selectedFile.value = null;
        form.pdf = null;
        
        // Reset file input
        if (fileInput.value) {
            fileInput.value.value = '';
        }
        
    } catch (err: any) {
        uploadStatus.value = err.message || 'Upload failed';
    } finally {
        isUploading.value = false;
    }
};

const triggerFileInput = () => {
    fileInput.value?.click();
};
</script>

<template>
    <Head title="Audit Upload" />
    
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-6">
            <div class="space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">Audit Upload</h1>
                <p class="text-muted-foreground">
                    Upload PDF audit reports for processing and analysis.
                </p>
            </div>
            
            <Card class="max-w-2xl">
                <CardHeader>
                    <CardTitle>Upload Audit PDF</CardTitle>
                    <CardDescription>
                        Select a PDF file to upload and process. Maximum file size is 10MB.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <Label for="pdf-upload">PDF File</Label>
                            <div class="flex items-center gap-4">
                                <input
                                    ref="fileInput"
                                    type="file"
                                    accept=".pdf"
                                    @change="handleFileSelect"
                                    class="hidden"
                                    id="pdf-upload"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="triggerFileInput"
                                    class="flex items-center gap-2"
                                >
                                    <Upload class="h-4 w-4" />
                                    Choose PDF File
                                </Button>
                                <span v-if="selectedFile" class="text-sm text-muted-foreground">
                                    {{ selectedFile.name }} ({{ Math.round(selectedFile.size / 1024) }}KB)
                                </span>
                            </div>
                        </div>
                        
                        <div v-if="uploadStatus" class="text-sm" :class="{
                            'text-green-600': uploadStatus.includes('successfully'),
                            'text-red-600': !uploadStatus.includes('successfully')
                        }">
                            {{ uploadStatus }}
                        </div>
                        
                        <Button
                            @click="uploadFile"
                            :disabled="!selectedFile || isUploading"
                            class="w-full"
                        >
                            <LoaderCircle v-if="isUploading" class="h-4 w-4 animate-spin mr-2" />
                            {{ isUploading ? 'Processing...' : 'Upload and Process' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>