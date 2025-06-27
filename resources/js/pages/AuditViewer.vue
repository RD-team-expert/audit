<script setup>
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Upload, FileText, CheckCircle, XCircle, AlertCircle } from 'lucide-vue-next'

// State management
const selectedFile = ref(null)
const isProcessing = ref(false)
const auditData = ref(null)
const error = ref(null)
const pdfText = ref('')
const activeTab = ref('sections')

// File input handler
const handleFileSelect = (event) => {
  const file = event.target.files[0]
  if (file && file.type === 'application/pdf') {
    selectedFile.value = file
    error.value = null
  } else {
    error.value = 'Please select a valid PDF file'
    selectedFile.value = null
  }
}

// PDF parsing using PDF.js library
const processPDF = async () => {
  if (!selectedFile.value) return
  
  isProcessing.value = true
  error.value = null
  
  try {
    // Load PDF.js library dynamically
    const pdfjsLib = await import('pdfjs-dist')
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'
    
    const arrayBuffer = await selectedFile.value.arrayBuffer()
    const pdf = await pdfjsLib.getDocument(arrayBuffer).promise
    
    let fullText = ''
    
    // Extract text from all pages
    for (let i = 1; i <= pdf.numPages; i++) {
      const page = await pdf.getPage(i)
      const textContent = await page.getTextContent()
      const pageText = textContent.items.map(item => item.str).join(' ')
      fullText += pageText + '\n'
    }
    
    pdfText.value = fullText
    
    // Parse the extracted text
    const parsedData = parseAuditData(fullText)
    auditData.value = parsedData
    
  } catch (err) {
    error.value = `Failed to process PDF: ${err.message}`
    console.error('PDF processing error:', err)
  } finally {
    isProcessing.value = false
  }
}

// Parse audit data from text (replicating PHP logic)
const parseAuditData = (text) => {
  const data = {
    metadata: extractMetadata(text),
    sections: extractSections(text),
    questions: extractQuestions(text)
  }
  return data
}

// Extract metadata from PDF text
const extractMetadata = (text) => {
  const matchText = (pattern, castType = 'string') => {
    const match = text.match(new RegExp(pattern, 'i'))
    if (!match || !match[1]) return null
    return castType === 'float' ? parseFloat(match[1]) : match[1].trim()
  }
  
  return {
    restaurant_name: matchText('Restaurant Name:\\s*(.+?)\\n'),
    address: matchText('Address:\\s*(.+?)\\n'),
    phone: matchText('Phone:\\s*(.+?)\\n'),
    form_type: matchText('Form Type:\\s*(.+?)\\n'),
    start_date: matchText('Start Date:\\s*(.+?)\\n'),
    end_date: matchText('End Date:\\s*(.+?)\\n'),
    upload_date: matchText('Upload Date:\\s*(.+?)\\n'),
    auditor: matchText('Auditor:\\s*(.+?)\\n'),
    overall_score: matchText('Overall Score:\\s*([\\d.]+)', 'float')
  }
}

// Extract section summaries
const extractSections = (text) => {
  const sections = []
  const regex = /([A-Za-z &\/\-]+)\s+(\d+|N\/A)\s+(\d+|N\/A)\s+((\d+\.\d+%)|N\/A)/g
  let match
  
  while ((match = regex.exec(text)) !== null) {
    sections.push({
      category: match[1].trim(),
      points: match[2] === 'N/A' ? null : parseInt(match[2]),
      total_points: match[3] === 'N/A' ? null : parseInt(match[3]),
      score: match[4] === 'N/A' ? null : match[4]
    })
  }
  
  return sections
}

// Extract questions from table format
const extractQuestions = (text) => {
  const questions = []
  const lines = text.split('\n')
  let currentCategory = ''
  let inTableSection = false
  
  for (const line of lines) {
    const trimmedLine = line.trim()
    if (!trimmedLine) continue
    
    // Detect section headers
    if (/^([A-Za-z][A-Za-z\s&\/\-]+)$/.test(trimmedLine) && 
        !/^(Image|Yes|No|N\/A|\d)/.test(trimmedLine)) {
      currentCategory = trimmedLine
      inTableSection = true
      continue
    }
    
    // Skip table headers
    if (/Report\s+Category|Question|Answer|Points|Percent/.test(trimmedLine)) {
      continue
    }
    
    // Parse table rows
    const tableMatch = trimmedLine.match(/^(\w+)\s+(.+?)\s+(Yes|No|N\/A)\s+(\d+)\s+(\d+)\s+([\d.]+%)$/)
    if (inTableSection && tableMatch) {
      let reportCategory = tableMatch[1].trim()
      const question = tableMatch[2].trim()
      const answer = tableMatch[3].trim()
      const pointsCurrent = parseInt(tableMatch[4])
      const pointsTotal = parseInt(tableMatch[5])
      const percent = parseFloat(tableMatch[6].replace('%', ''))
      
      // Use current category if report category is generic
      if (['Image', 'Food'].includes(reportCategory)) {
        reportCategory = currentCategory
      }
      
      questions.push({
        report_category: reportCategory,
        question,
        answer,
        points_current: pointsCurrent,
        points_total: pointsTotal,
        percent,
        comments: null
      })
    }
  }
  
  return questions
}

// Computed properties for data display
const hasData = computed(() => auditData.value !== null)
const scoreColor = computed(() => {
  if (!auditData.value?.metadata?.overall_score) return 'text-gray-500'
  const score = auditData.value.metadata.overall_score
  if (score >= 90) return 'text-green-600'
  if (score >= 70) return 'text-yellow-600'
  return 'text-red-600'
})

const questionsByCategory = computed(() => {
  if (!auditData.value?.questions) return {}
  return auditData.value.questions.reduce((acc, question) => {
    const category = question.report_category || 'Uncategorized'
    if (!acc[category]) acc[category] = []
    acc[category].push(question)
    return acc
  }, {})
})
</script>

<template>
  <Head title="Audit Viewer" />
  
  <div class="container mx-auto p-6 space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold tracking-tight">Audit Viewer</h1>
        <p class="text-gray-600">Upload and analyze audit PDF files with JavaScript</p>
      </div>
    </div>

    <!-- File Upload Section -->
    <Card>
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Upload class="h-5 w-5" />
          Upload Audit PDF
        </CardTitle>
        <CardDescription>
          Select a PDF file to extract and analyze audit data
        </CardDescription>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="flex items-center gap-4">
          <input
            type="file"
            accept=".pdf"
            @change="handleFileSelect"
            class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:cursor-not-allowed disabled:opacity-50"
          />
          <Button 
            @click="processPDF" 
            :disabled="!selectedFile || isProcessing"
            class="whitespace-nowrap"
          >
            <FileText class="h-4 w-4 mr-2" />
            {{ isProcessing ? 'Processing...' : 'Process PDF' }}
          </Button>
        </div>
        
        <div v-if="selectedFile" class="text-sm text-gray-600">
          Selected: {{ selectedFile.name }} ({{ Math.round(selectedFile.size / 1024) }} KB)
        </div>
        
        <div v-if="error" class="flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-md">
          <AlertCircle class="h-4 w-4 text-red-600" />
          <span class="text-red-700">{{ error }}</span>
        </div>
      </CardContent>
    </Card>

    <!-- Results Section -->
    <div v-if="hasData" class="space-y-6">
      <!-- Metadata Card -->
      <Card>
        <CardHeader>
          <CardTitle class="flex items-center justify-between">
            <span>Audit Report Details</span>
            <span v-if="auditData.metadata.overall_score" :class="scoreColor" class="px-3 py-1 rounded-full bg-gray-100 text-sm font-medium">
              {{ auditData.metadata.overall_score }}% Overall Score
            </span>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div v-for="(value, key) in auditData.metadata" :key="key" class="space-y-1">
              <div class="text-sm font-medium text-gray-600 capitalize">
                {{ key.replace('_', ' ') }}
              </div>
              <div class="text-sm">{{ value || 'N/A' }}</div>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Tab Navigation -->
      <div class="border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
          <button
            @click="activeTab = 'sections'"
            :class="activeTab === 'sections' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
            class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm"
          >
            Section Summary
          </button>
          <button
            @click="activeTab = 'questions'"
            :class="activeTab === 'questions' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
            class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm"
          >
            Detailed Questions
          </button>
          <button
            @click="activeTab = 'raw'"
            :class="activeTab === 'raw' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
            class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm"
          >
            Raw Text
          </button>
        </nav>
      </div>
        
      <!-- Section Summary Tab -->
      <div v-if="activeTab === 'sections'" class="space-y-4">
        <Card>
          <CardHeader>
            <CardTitle>Section Summary</CardTitle>
            <CardDescription>Overview of audit sections and scores</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Points Earned</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Points</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="section in auditData.sections" :key="section.category">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ section.category }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ section.points ?? 'N/A' }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ section.total_points ?? 'N/A' }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <span :class="section.score && parseFloat(section.score) >= 80 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'" class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                        {{ section.score ?? 'N/A' }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>
      
      <!-- Detailed Questions Tab -->
      <div v-if="activeTab === 'questions'" class="space-y-4">
        <div v-for="(questions, category) in questionsByCategory" :key="category" class="space-y-2">
          <Card>
            <CardHeader>
              <CardTitle class="text-lg">{{ category }}</CardTitle>
            </CardHeader>
            <CardContent>
              <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                  <thead class="bg-gray-50">
                    <tr>
                      <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Question</th>
                      <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Answer</th>
                      <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Points</th>
                      <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="question in questions" :key="question.question">
                      <td class="px-6 py-4 text-sm text-gray-900 max-w-md">{{ question.question }}</td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <span :class="question.answer === 'Yes' ? 'bg-green-100 text-green-800' : question.answer === 'No' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'" class="inline-flex items-center px-2 py-1 text-xs font-semibold rounded-full">
                          <CheckCircle v-if="question.answer === 'Yes'" class="h-3 w-3 mr-1" />
                          <XCircle v-else-if="question.answer === 'No'" class="h-3 w-3 mr-1" />
                          {{ question.answer }}
                        </span>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ question.points_current }}/{{ question.points_total }}</td>
                      <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ question.percent }}%</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
      
      <!-- Raw Text Tab -->
      <div v-if="activeTab === 'raw'">
        <Card>
          <CardHeader>
            <CardTitle>Raw Extracted Text</CardTitle>
            <CardDescription>The complete text extracted from the PDF</CardDescription>
          </CardHeader>
          <CardContent>
            <pre class="whitespace-pre-wrap text-xs bg-gray-100 p-4 rounded-md max-h-96 overflow-y-auto">{{ pdfText }}</pre>
          </CardContent>
        </Card>
      </div>
    </div>
  </div>
</template>